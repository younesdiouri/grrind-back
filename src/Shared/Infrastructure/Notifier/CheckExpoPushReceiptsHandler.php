<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Notifier;

use App\Shared\Application\DeadPushTokens;
use App\Shared\Application\PushRejection;
use App\Shared\Domain\Notification\PendingPushReceipt;
use App\Shared\Infrastructure\Doctrine\PendingPushReceiptRepository;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Notifier\Transport\Dsn;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Interroge les reçus de livraison Expo pour les tickets acceptés, et route le résultat vers
 * la décision déjà écrite par le #140 : {@see PushRejection::invalidatesDevice()}.
 *
 * **Le quatrième cas de la règle n°0 — du code à nous, en dernier recours, et vérifié comme
 * tel.** `symfony/expo-notifier` (v8.1.0) ne couvre que l'envoi : `ExpoTransport::doSend()`
 * n'appelle que `exp.host/--/api/v2/push/send`, il n'y a ni méthode ni point d'extension pour
 * `exp.host/--/api/v2/push/getReceipts`. L'appel ci-dessous est donc écrit à la main, avec
 * `HttpClientInterface` — le composant Symfony, pas un client maison — plutôt qu'en
 * réinventant un second bridge.
 *
 * **Un seul appel HTTP pour toute une rafale, jamais un par ticket.** {@see CheckExpoPushReceipts}
 * ne porte aucune charge utile : ce handler relit lui-même ce qui est mûr
 * ({@see PendingPushReceiptRepository::dueForCheck()}), tous émetteurs confondus. Une guilde de
 * trente membres qui déclenche trente appels à {@see ExpoPushSender::send()} ne produit ainsi
 * qu'un seul appel `getReceipts` une fois le délai écoulé pour le lot entier — les trente
 * messages différés qui suivent ne trouvent alors plus rien à faire. `MAX_IDS_PER_REQUEST` est
 * la limite qu'Expo documente pour cet appel, pas une estimation : à ce volume, une seule
 * guilde ne peut pas l'atteindre (`game.community.maximum_members`).
 *
 * **Un reçu absent n'est ni positif ni négatif.** Expo peut ne pas encore l'avoir produit — le
 * délai de {@see CheckExpoPushReceipts::DELAY_MINUTES} n'est qu'une recommandation, pas une
 * garantie — ou l'avoir déjà purgé : « Receipts are cleared after 24 hours. » Tant que
 * `GIVE_UP_AFTER_HOURS` n'est pas dépassé, ce handler se redemande une seconde chance
 * ({@see CheckExpoPushReceipts}, même délai) ; au-delà, il abandonne la ligne — sans jamais la
 * supprimer ni invalider le jeton sur cette seule absence, voir le docblock de
 * {@see PendingPushReceipt}. C'est le test qui compte dans ce ticket, au même titre que
 * « `DeviceNotRegistered` supprime, une panne ne supprime pas » l'était au #140 : une panne
 * réseau ici tombe dans le même cas qu'un reçu absent, {@see fetchReceipts()} rend une liste
 * vide plutôt que de lever.
 *
 * **Le jeton Bearer, s'il existe, vient de `EXPO_DSN` — pas d'un second réglage.** Expo permet
 * d'exiger ce jeton sur toute requête de son API, `getReceipts` compris ; `ExpoTransport`
 * l'envoie déjà pour l'envoi, extrait du DSN par {@see Dsn} (`symfony/notifier`). Réutiliser le
 * même DSN et le même parseur — jamais une regex maison — évite qu'un jeton mis à jour dans
 * `.env.local` diverge entre l'envoi et la lecture des reçus.
 */
#[AsMessageHandler]
final readonly class CheckExpoPushReceiptsHandler
{
    private const string RECEIPTS_ENDPOINT = 'https://exp.host/--/api/v2/push/getReceipts';

    /**
     * @see https://docs.expo.dev/push-notifications/sending-notifications/#receipts
     *      « You are trying to get more than 1000 push receipts in one request. »
     */
    private const int MAX_IDS_PER_REQUEST = 1000;

    /** @see https://docs.expo.dev/push-notifications/sending-notifications/#receipts « Receipts are cleared after 24 hours. » */
    private const int GIVE_UP_AFTER_HOURS = 24;

    public function __construct(
        private HttpClientInterface $httpClient,
        private PendingPushReceiptRepository $receipts,
        private DeadPushTokens $deadPushTokens,
        // Sans `#[Target]`, volontairement (#155) : `CheckExpoPushReceipts` n'est pas un
        // `DomainEvent`, il reste sur le bus strict que `MessageBusInterface` résout par
        // défaut. Symfony ne crée d'alias nommé que pour les bus non par défaut — voir le
        // docblock de `messenger.yaml`.
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private string $expoDsn,
    ) {
    }

    public function __invoke(CheckExpoPushReceipts $message): void
    {
        $now = $this->clock->now();
        $due = $this->receipts->dueForCheck(
            $now->modify(\sprintf('-%d minutes', CheckExpoPushReceipts::DELAY_MINUTES)),
            self::MAX_IDS_PER_REQUEST,
        );

        if ([] === $due) {
            return;
        }

        $receiptsById = $this->fetchReceipts($due);
        $stillPending = false;

        foreach ($due as $pending) {
            $receipt = $receiptsById[$pending->ticketId()] ?? null;

            if (null === $receipt) {
                if ($this->hasExpired($pending, $now)) {
                    // Abandon : la ligne reste, elle n'a plus de valeur mais ce n'est pas à
                    // ce handler de la purger — voir le docblock de PendingPushReceipt (#43).
                    continue;
                }

                $stillPending = true;

                continue;
            }

            $this->resolve($pending, $receipt);
        }

        $this->receipts->flush();

        if ($stillPending) {
            $this->bus->dispatch(new CheckExpoPushReceipts(), [new DelayStamp(CheckExpoPushReceipts::DELAY_MINUTES * 60_000)]);
        }
    }

    /**
     * @param list<PendingPushReceipt> $due
     *
     * @return array<string, array{status: string, message?: string, details?: array{error?: string}}>
     */
    private function fetchReceipts(array $due): array
    {
        $ids = array_map(static fn (PendingPushReceipt $r): string => $r->ticketId(), $due);
        $token = new Dsn($this->expoDsn)->getUser();

        try {
            $response = $this->httpClient->request('POST', self::RECEIPTS_ENDPOINT, [
                'headers' => [
                    'Authorization' => null !== $token ? \sprintf('Bearer %s', $token) : null,
                ],
                'json' => ['ids' => $ids],
            ]);

            /** @var array{data?: array<string, array{status: string, message?: string, details?: array{error?: string}}>} $result */
            $result = $response->toArray();

            return $result['data'] ?? [];
        } catch (ExceptionInterface $e) {
            // Panne réseau, réponse non-200, JSON malformé : rien n'est résolu, tout retombe
            // dans le cas « reçu absent » ci-dessus — jamais une invalidation, jamais une
            // exception qui ferait tomber le message.
            $this->logger->warning('Impossible d\'interroger les reçus Expo.', ['exception' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param array{status: string, message?: string, details?: array{error?: string}} $receipt
     */
    private function resolve(PendingPushReceipt $pending, array $receipt): void
    {
        if ('error' === $receipt['status']) {
            $rejection = PushRejection::tryFrom($receipt['details']['error'] ?? '') ?? PushRejection::Unknown;

            $this->logger->warning('Reçu Expo refusé.', [
                'pushToken' => $pending->pushToken(),
                'rejection' => $rejection->value,
            ]);

            // Même décision que ExpoPushSender au ticket d'envoi — voir le docblock de
            // PushRejection : les deux chemins ne doivent jamais diverger.
            if ($rejection->invalidatesDevice()) {
                $this->deadPushTokens->discard($pending->pushToken());
            }
        }

        $this->receipts->remove($pending);
    }

    private function hasExpired(PendingPushReceipt $pending, DateTimeImmutable $now): bool
    {
        $ageInHours = ($now->getTimestamp() - $pending->createdAt()->getTimestamp()) / 3600;

        return $ageInHours >= self::GIVE_UP_AFTER_HOURS;
    }
}
