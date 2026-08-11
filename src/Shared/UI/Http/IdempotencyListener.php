<?php

declare(strict_types=1);

namespace App\Shared\UI\Http;

use App\Shared\Domain\Idempotency\Exception\IdempotencyKeyInFlight;
use App\Shared\Domain\Idempotency\Exception\IdempotencyKeyRequired;
use App\Shared\Domain\Idempotency\Exception\IdempotencyKeyReused;
use App\Shared\Domain\Idempotency\IdempotencyRecord;
use App\Shared\Domain\Idempotency\RecordStatus;
use App\Shared\Infrastructure\Doctrine\IdempotencyRecordRepository;
use LogicException;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Fait tenir la promesse de {@see Idempotent} : une clé, une écriture, une réponse.
 *
 * Deux temps. Avant le contrôleur on **réserve** la clé, et si elle est déjà prise on
 * tranche sans exécuter : rejeu à rendre, requête concurrente à refuser, ou clé recyclée
 * sur un autre contenu. Après, on **fige** la réponse produite — ou on relâche la clé si
 * rien n'a pu être écrit.
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
    ) {
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

            return;
        }

        $headers = [];
        foreach (self::REPLAYED_HEADERS as $name) {
            $value = $response->headers->get($name);

            if (null !== $value) {
                $headers[$name] = $value;
            }
        }

        $this->records->complete($reservation, $status, $headers, (string) $response->getContent());
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
