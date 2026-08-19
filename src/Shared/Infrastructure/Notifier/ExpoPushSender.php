<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Notifier;

use App\Shared\Application\DeadPushTokens;
use App\Shared\Application\PushNotification;
use App\Shared\Application\PushRejection;
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
 *
 * **C'est ici, et nulle part ailleurs, que le vocabulaire Expo s'arrête.** Le
 * `TransportException` du bridge ne porte son code d'erreur (`DeviceNotRegistered`, …)
 * que dans un message formaté — voir {@see PushRejection} pour le format exact et
 * pourquoi ce n'est pas plus fiable. `rejectionFrom()` en fait la seule extraction du
 * projet ; un consommateur du port (#131 compris) ne lit qu'un enum fermé, jamais une
 * sous-chaîne d'un message tiers.
 *
 * **#131 : un refus immédiat invalide déjà le jeton.** Expo distingue un refus au ticket
 * (connu tout de suite, ici) d'un refus au reçu de livraison (connu plus tard, interrogé
 * en asynchrone) — mais les deux portent le même {@see PushRejection}, et
 * {@see PushRejection::invalidatesDevice()} est le seul endroit qui décide. Le chemin du
 * reçu est posé par ce même ticket, mais **pas ici** : voir
 * {@see ReceiptSchedulingPushSender}, qui décore ce sender plutôt que d'ajouter à sa
 * construction une dépendance Doctrine et Messenger qu'il n'avait aucune raison de porter
 * — cette classe reste ce que son docblock annonce, un traducteur vers Expo, testable
 * sans conteneur ni réseau (voir `ExpoPushSenderTest`).
 */
final readonly class ExpoPushSender implements PushSender
{
    public function __construct(
        private TexterInterface $texter,
        private PushTargets $pushTargets,
        private DeadPushTokens $deadPushTokens,
        private LoggerInterface $logger,
    ) {
    }

    public function send(Uuid $userId, PushNotification $notification): array
    {
        $tickets = [];

        foreach ($this->pushTargets->of($userId, $notification->category) as $pushToken) {
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
                    'categoryId' => $notification->category->value,
                    'channelId' => $notification->category->value,
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
                'category' => $notification->category->value,
                'transport' => $sent?->getTransport(),
                'ticketId' => $sent?->getMessageId(),
            ]);

            return PushTicket::accepted($pushToken, $sent?->getMessageId());
        } catch (TransportExceptionInterface $e) {
            $rejection = self::rejectionFrom($e->getMessage());

            $this->logger->warning('Notification push refusée par Expo.', [
                'category' => $notification->category->value,
                'rejection' => $rejection->value,
                'reason' => $e->getMessage(),
            ]);

            // #131 : le fournisseur est formel, pas approximatif — sur ce seul code, le
            // jeton est effacé sèchement. Tout le reste (débit, credentials, réseau) se
            // retente au prochain envoi, sans toucher à la table.
            if ($rejection->invalidatesDevice()) {
                $this->deadPushTokens->discard($pushToken);
            }

            return PushTicket::rejected($pushToken, $rejection, $e->getMessage());
        }
    }

    /**
     * Récupère le code Expo dans le message formaté par `ExpoTransport::doSend()` — voir
     * le docblock de {@see PushRejection} pour le format exact. Un message qui ne s'y
     * conforme pas (panne réseau, réponse non-200 sans détail structuré, un futur format
     * de bundle) rend {@see PushRejection::Unknown} plutôt que de lever : un format
     * inattendu ne doit jamais faire tomber l'envoi aux autres jetons du joueur.
     */
    private static function rejectionFrom(string $message): PushRejection
    {
        if (1 !== preg_match('/\(([^()]+)\)$/', $message, $matches)) {
            return PushRejection::Unknown;
        }

        return PushRejection::tryFrom($matches[1]) ?? PushRejection::Unknown;
    }
}
