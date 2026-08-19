<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Notifier;

use App\Shared\Application\PushNotification;
use App\Shared\Application\PushSender;
use App\Shared\Application\PushTargets;
use App\Shared\Application\PushTicket;
use Psr\Log\LoggerInterface;
use Symfony\Component\Notifier\Bridge\Expo\ExpoOptions;
use Symfony\Component\Notifier\Exception\TransportExceptionInterface;
use Symfony\Component\Notifier\Message\PushMessage;
use Symfony\Component\Notifier\TexterInterface;
use Symfony\Component\Uid\Uuid;

/**
 * L'implémentation du port {@see PushSender} : traduit une {@see PushNotification} —
 * `category` et `groupingKey` — vers le vocabulaire qu'Expo attend (`categoryId`,
 * `channelId`, `data`), et délègue l'appel au `TexterInterface` du framework. C'est tout
 * ce qu'elle fait : aucune règle de jeu, aucune décision de qui notifier.
 *
 * **Toujours journalisée, quel que soit l'environnement.** C'est ce qui rend `dev` et
 * `test` sûrs sans code à part : `EXPO_DSN=null://null` par défaut (voir `.env` et
 * `config/packages/notifier.yaml`) fait qu'aucun appel HTTP ne part, et cette ligne de
 * log est alors la seule preuve qu'un envoi a eu lieu — d'où « mode journal ». En
 * production, la même ligne sert d'observabilité normale.
 *
 * **Un appel par jeton, jamais un lot.** `ExpoTransport::doSend()` n'accepte qu'un seul
 * `to` par requête ; Expo autorise pourtant jusqu'à 100 destinataires par appel, mais le
 * bridge Symfony ne l'exploite pas. Regrouper les appels se fera ici, dans la boucle
 * ci-dessous, le jour où le volume le justifie — pas en réécrivant le bridge.
 *
 * **Un jeton refusé n'interrompt pas les autres.** `TransportExceptionInterface` sur un
 * jeton mort ne doit pas priver les autres appareils du joueur de la notification — même
 * logique que l'import qui écarte un workout sans annuler les neuf autres. L'échec est
 * donc consigné dans le {@see PushTicket} de ce jeton, jamais relancé.
 */
final readonly class ExpoPushSender implements PushSender
{
    public function __construct(
        private TexterInterface $texter,
        private PushTargets $pushTargets,
        private LoggerInterface $logger,
    ) {
    }

    public function send(Uuid $userId, PushNotification $notification): array
    {
        $tickets = [];

        foreach ($this->pushTargets->of($userId) as $pushToken) {
            $tickets[] = $this->sendTo($pushToken, $notification);
        }

        return $tickets;
    }

    private function sendTo(string $pushToken, PushNotification $notification): PushTicket
    {
        $message = new PushMessage(
            $notification->title,
            $notification->body,
            new ExpoOptions(
                to: $pushToken,
                options: [
                    'categoryId' => $notification->category,
                    'channelId' => $notification->category,
                ],
                data: ['groupingKey' => $notification->groupingKey],
            ),
        );

        try {
            // `?SentMessage` : le contrat couvre un transport asynchrone qui rendrait la
            // main avant la réponse. Ni Expo ni le transport nul ne sont dans ce cas —
            // les deux répondent en synchrone — mais le type l'autorise, donc on le gère.
            $sent = $this->texter->send($message);

            $this->logger->info('Notification push envoyée.', [
                'category' => $notification->category,
                'transport' => $sent?->getTransport(),
                'ticketId' => $sent?->getMessageId(),
            ]);

            return PushTicket::accepted($pushToken, $sent?->getMessageId());
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('Notification push refusée par Expo.', [
                'category' => $notification->category,
                'reason' => $e->getMessage(),
            ]);

            return PushTicket::rejected($pushToken, $e->getMessage());
        }
    }
}
