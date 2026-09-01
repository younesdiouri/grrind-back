<?php

declare(strict_types=1);

namespace App\Shared\Application;

use App\Shared\Infrastructure\Doctrine\PendingSessionCreditRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Tombeau du consommateur #252 : il conserve le message sérialisé et le routage le temps que
 * les lignes historiques disparaissent de `outbox` et `failed`, puis referme idempotemment la
 * fenêtre correspondante. Il n'a volontairement ni `PushSender` ni `NotificationAttempt` :
 * l'auteur est désormais notifié localement par l'app iOS.
 */
final readonly class AnnounceSessionCreditHandler
{
    public function __construct(private PendingSessionCreditRepository $pending)
    {
    }

    #[AsMessageHandler]
    public function __invoke(AnnounceSessionCredit $message): void
    {
        $this->pending->close($message->playerId, $message->windowId);
    }
}
