<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Notifier;

use App\Shared\Application\DeadPushTokens;
use App\Shared\Application\PushNotification;
use App\Shared\Application\PushRejection;
use App\Shared\Application\PushSender;
use App\Shared\Application\PushTargets;
use App\Shared\Application\PushTicket;
use App\Shared\UI\Push\PushNotificationData;
use Psr\Log\LoggerInterface;
use Symfony\Component\Notifier\Bridge\Expo\ExpoOptions;
use Symfony\Component\Notifier\Exception\TransportExceptionInterface;
use Symfony\Component\Notifier\Message\PushMessage;
use Symfony\Component\Notifier\Transport\Transports;
use Symfony\Component\Uid\Uuid;

/**
 * L'implémentation du port {@see PushSender} : traduit une {@see PushNotification} —
 * `category`, `groupingKey` et `route` — vers le vocabulaire qu'Expo attend (`categoryId`,
 * `channelId`, `data`), et délègue l'appel au `Transports` du framework. C'est tout ce
 * qu'elle fait : aucune règle de jeu, aucune décision de qui notifier.
 *
 * **Pourquoi `Transports` (`texter.transports`), pas `TexterInterface` ni même
 * `TransportInterface` (#150).** `TexterInterface extends TransportInterface` : un
 * `Texter` est donc un `TransportInterface` valide, et `Texter::send()` rend toujours
 * `null` dès qu'un bus Messenger lui est injecté — ce que `FrameworkExtension` fait
 * automatiquement puisque Messenger est activé sur ce projet, qu'on lui ait demandé ou
 * non. Le push part quand même (traité en synchrone, aucune route Messenger ne vise les
 * messages du Notifier), mais `Texter::send()` jette le `SentMessage` que le bridge a
 * pourtant construit avec le `ticketId` d'Expo — d'où un push livré et un `PushTicket`
 * sans identifiant, qui éteignait toute la chaîne de reçus du #131. Typer ce
 * constructeur en `TransportInterface` aurait laissé `'@texter'` câblable à sa place :
 * le conteneur aurait compilé, et la panne serait revenue sans qu'aucun test ne rougisse.
 * `Transports` (la classe concrète du service `texter.transports`, câblé dans
 * `config/services.yaml`) est `final` et redéclare `send(): SentMessage`, **non
 * nullable** — un type qui exclut `Texter` par construction, pas seulement par
 * convention, et qui dispense PHPStan de nous croire sur parole : la garantie est une
 * propriété du type, pas d'une annotation. C'est aussi ce qui répare le chemin des
 * refus : un `TransportException` du bridge n'est plus enveloppé dans un
 * `HandlerFailedException` par `HandleMessageMiddleware`, donc le `catch` ci-dessous se
 * déclenche de nouveau en conditions réelles. Rien d'observable n'est perdu :
 * `AbstractTransport::send()` dispatche lui-même le `MessageEvent`, donc le profiler et
 * le listener de log du Notifier continuent de voir les envois.
 *
 * **`data` (#144) : `groupingKey` et `route`, jamais autre chose.** Les deux répondent à
 * deux questions différentes — quelle notification celle-ci remplace-t-elle, où le tap
 * doit-il mener — et aucune donnée de jeu ne s'y ajoute : XP, niveau ou discipline
 * vieilliraient dans une notification qui peut dormir des heures avant d'être touchée, là
 * où `title`/`body` assument déjà de l'être. Ce que le tap affiche ensuite se relit depuis
 * l'API, jamais transporté ici.
 *
 * **Sa forme n'est plus écrite ici (#147).** Les clés du `data` sont du contrat — le
 * client les décode — et un contrat se génère : c'est {@see PushNotificationData} qui les
 * nomme, et `openapi.yaml` qui les décrit depuis cette même classe. Cette traduction-ci
 * reste une traduction vers Expo : elle sait seulement que le champ s'appelle `data`.
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
        private Transports $transport,
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
                data: PushNotificationData::of($notification)->toArray(),
            ),
        );

        try {
            $sent = $this->transport->send($message);

            $this->logger->info('Notification push envoyée.', [
                'category' => $notification->category->value,
                'transport' => $sent->getTransport(),
                'ticketId' => $sent->getMessageId(),
            ]);

            return PushTicket::accepted($pushToken, $sent->getMessageId());
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
