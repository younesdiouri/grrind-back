<?php

declare(strict_types=1);

namespace App\Shared\UI\Http;

use App\Shared\Domain\Idempotency\Exception\IdempotencyKeyInFlight;
use App\Shared\Domain\Idempotency\Exception\IdempotencyKeyRequired;
use App\Shared\Domain\Idempotency\Exception\IdempotencyKeyReused;
use App\Shared\Domain\Idempotency\IdempotencyRecord;
use App\Shared\Domain\Idempotency\RecordStatus;
use App\Shared\Infrastructure\Doctrine\IdempotencyRecordRepository;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Fait tenir la promesse de {@see Idempotent} : une clé, une écriture, une réponse.
 *
 * La réservation est visible avant l'action. `kernel.controller_arguments` encadre
 * ensuite le vrai contrôleur, sa sérialisation et le reçu dans une transaction DBAL
 * sur la connexion de l'ORM et de l'outbox. Les transactions métier deviennent des
 * savepoints ; leurs verrous tiennent jusqu'à ce COMMIT extérieur (#366).
 * https://symfony.com/doc/current/components/http_kernel.html
 * https://www.doctrine-project.org/projects/doctrine-dbal/en/4.4/reference/transactions.html
 *
 * `kernel.response` ne traite plus que les refus après rollback et les erreurs avant
 * l'action. Une panne HTTP après COMMIT ne peut donc ni effacer ni réécrire le reçu.
 *
 * L'accroche est sur `kernel.controller` et non `kernel.request` : c'est le premier
 * moment où le contrôleur visé est connu, donc où ses attributs sont lisibles.
 */
final readonly class IdempotencyListener
{
    /** Prévient le client qu'il lit une réponse conservée, et prouve en test que rien
     * n'a été réexécuté. */
    public const string REPLAY_HEADER = 'Idempotent-Replay';
    /** Relie les deux temps. Préfixe `_` : convention Symfony pour l'interne. */
    private const string RESERVATION = '_idempotency_reservation';

    /** Tout le reste — `Date`, `Cache-Control`, cookies — appartient à la requête
     * d'origine et n'a plus de sens. */
    private const array REPLAYED_HEADERS = ['Content-Type', 'Location'];

    public function __construct(
        private IdempotencyRecordRepository $records,
        private Security $security,
        private ClockInterface $clock,
        private EntityManagerInterface $em,
    ) {
    }

    #[AsEventListener(event: KernelEvents::CONTROLLER_ARGUMENTS)]
    public function protect(ControllerArgumentsEvent $event): void
    {
        $request = $event->getRequest();
        $reservation = $request->attributes->get(self::RESERVATION);
        if (!$event->isMainRequest() || !$reservation instanceof Uuid) {
            return;
        }

        $controller = $event->getController();
        $event->setController(fn (mixed ...$arguments): Response => $this->transact($controller, $arguments, $request, $reservation));
    }

    /**
     * @throws IdempotencyKeyRequired
     * @throws IdempotencyKeyReused
     * @throws IdempotencyKeyInFlight
     */
    #[AsEventListener(event: KernelEvents::CONTROLLER)]
    public function reserve(ControllerEvent $event): void
    {
        if (!$event->isMainRequest() || [] === $event->getAttributes(Idempotent::class)) {
            return;
        }

        $request = $event->getRequest();
        $key = self::keyOf($request);
        $userId = $this->currentUserId();
        $fingerprint = self::fingerprint($request);

        $reservation = $this->records->claim($userId, $key, $fingerprint, $this->clock->now());

        if (null !== $reservation) {
            $request->attributes->set(self::RESERVATION, $reservation);

            return;
        }

        // La clé est prise. Par qui, et pour quoi ?
        $held = $this->records->ofKey($userId, $key);

        // Expirée puis purgée entre la réservation et cette lecture : la course est
        // perdue mais rien n'est cassé, le client réessaie et repartira gagnant.
        if (null === $held || RecordStatus::InFlight === $held->status()) {
            throw new IdempotencyKeyInFlight($key);
        }

        if (!$held->covers($fingerprint)) {
            throw new IdempotencyKeyReused($key);
        }

        // Rejeu légitime : on court-circuite le contrôleur en lui substituant la
        // réponse d'origine. Aucune règle métier ne sera rejouée.
        $replayed = self::replay($held);
        $event->setController(static fn (): Response => $replayed);
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function record(ResponseEvent $event): void
    {
        $reservation = $event->getRequest()->attributes->get(self::RESERVATION);

        if (!$event->isMainRequest() || !$reservation instanceof Uuid) {
            return;
        }

        $response = $event->getResponse();
        $status = $response->getStatusCode();

        // Une panne n'est pas un résultat. On rend la clé pour que le client retente ;
        // un refus métier, lui, est une réponse à part entière et se rejoue tel quel.
        if ($status >= Response::HTTP_INTERNAL_SERVER_ERROR) {
            $this->records->release($reservation);
            $event->getRequest()->attributes->remove(self::RESERVATION);

            return;
        }

        $this->complete($reservation, $response);
        $event->getRequest()->attributes->remove(self::RESERVATION);
    }

    /** @param array<mixed> $arguments */
    private function transact(callable $controller, array $arguments, Request $request, Uuid $reservation): Response
    {
        $connection = $this->em->getConnection();
        if (0 !== $connection->getTransactionNestingLevel()) {
            throw new LogicException('La transaction HTTP idempotente doit être extérieure aux transactions métier.');
        }

        $connection->beginTransaction();
        // Le chemin d'erreur ne retrouve cette clé qu'après un rollback confirmé.
        $request->attributes->remove(self::RESERVATION);
        try {
            $response = $controller(...$arguments);
            if (!$response instanceof Response) {
                throw new LogicException('Un contrôleur idempotent doit matérialiser sa réponse dans la transaction.');
            }
            if ($response->getStatusCode() >= Response::HTTP_BAD_REQUEST) {
                $connection->rollBack();
                $this->em->clear();
                $request->attributes->set(self::RESERVATION, $reservation);

                return $response;
            }
            $this->em->flush();
            $this->complete($reservation, $response);
        } catch (Throwable $error) {
            try {
                $connection->rollBack();
            } catch (Throwable $rollbackError) {
                $connection->close();
                $this->em->close();
                throw $rollbackError;
            }
            $this->em->clear();
            $request->attributes->set(self::RESERVATION, $reservation);
            throw $error;
        }

        try {
            $connection->commit();
        } catch (Throwable $error) {
            // COMMIT sans accusé : résultat incertain. Fermer défait ce qui n'a pas
            // été acquis ; ne jamais supprimer un reçu potentiellement déjà commis.
            $connection->close();
            $this->em->close();
            throw $error;
        }

        return $response;
    }

    private function complete(Uuid $reservation, Response $response): void
    {
        $headers = [];
        foreach (self::REPLAYED_HEADERS as $name) {
            $value = $response->headers->get($name);

            if (null !== $value) {
                $headers[$name] = $value;
            }
        }

        $this->records->complete($reservation, $response->getStatusCode(), $headers, (string) $response->getContent());
    }

    private static function replay(IdempotencyRecord $record): Response
    {
        return new Response(
            $record->responseBody(),
            $record->responseStatus() ?? Response::HTTP_OK,
            $record->responseHeaders() + [self::REPLAY_HEADER => 'true'],
        );
    }

    /**
     * @throws IdempotencyKeyRequired
     */
    private static function keyOf(Request $request): string
    {
        $key = trim($request->headers->get('Idempotency-Key', ''));

        if ('' === $key || \strlen($key) > IdempotencyRecord::KEY_MAX_LENGTH) {
            throw new IdempotencyKeyRequired();
        }

        return $key;
    }

    /**
     * Méthode et chemin en font partie : sans eux, une clé recyclée d'une route sur
     * l'autre passerait pour un rejeu. La query string en est absente, faute d'écriture
     * métier qui en dépende — c'est ici qu'il faudra l'ajouter le jour venu.
     */
    private static function fingerprint(Request $request): string
    {
        return hash('sha256', implode("\n", [
            $request->getMethod(),
            $request->getPathInfo(),
            $request->getContent(),
        ]));
    }

    /**
     * L'identifiant de sécurité étant l'UUID du compte, `Shared` sait *qui* écrit sans
     * rien connaître d'`Identity`.
     */
    private function currentUserId(): Uuid
    {
        $user = $this->security->getUser();

        if (null === $user || !Uuid::isValid($user->getUserIdentifier())) {
            throw new LogicException('Une route idempotente doit être authentifiée : la clé appartient à un joueur.');
        }

        return Uuid::fromString($user->getUserIdentifier());
    }
}
