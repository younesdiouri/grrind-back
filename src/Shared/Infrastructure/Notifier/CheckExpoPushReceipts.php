<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Notifier;

/**
 * « Va voir ce qui est mûr à interroger. » Un déclencheur, pas une charge utile : la liste des
 * tickets à vérifier se relit depuis {@see \App\Shared\Infrastructure\Doctrine\PendingPushReceiptRepository::dueForCheck()}
 * au moment du traitement, pas depuis ce message — voir le docblock du handler pour pourquoi
 * c'est ce détour qui permet un seul appel Expo pour toute une rafale de guilde plutôt qu'un
 * appel par notification envoyée.
 *
 * **Dispatché sur l'outbox comme {@see \App\Community\Application\AnnounceGuildActivity},
 * sans en être un `DomainEvent`** — voir la ligne dédiée de `config/packages/messenger.yaml`.
 *
 * **Toujours dispatché avec un `DelayStamp`**, jamais consommé immédiatement — deux fois :
 * une première fois par {@see ExpoPushSender::send()} dès
 * qu'un ticket est accepté, une seconde fois par {@see CheckExpoPushReceiptsHandler} lui-même
 * s'il reste des tickets sans réponse à retenter. `DELAY_MINUTES` sert aux deux : rien dans la
 * documentation Expo ne justifie un intervalle différent pour une seconde tentative.
 */
final readonly class CheckExpoPushReceipts
{
    /**
     * @see https://docs.expo.dev/push-notifications/sending-notifications/#receipts
     *      « We recommend checking push receipts 15 minutes after sending your push
     *      notifications. »
     */
    public const int DELAY_MINUTES = 15;
}
