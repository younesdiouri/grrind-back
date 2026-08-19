<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Application\PushNotification;
use App\Shared\Application\PushSender;
use Symfony\Component\Uid\Uuid;

/**
 * N'envoie rien nulle part — enregistre à qui et quoi `PushSender::send()` a été appelé,
 * pour qu'un test affirme précisément combien de pushes sont partis et pour qui, sans
 * dépendre du transport Expo `null://null` ni de ses logs (#133).
 *
 * Câblée uniquement en argument d'`AnnounceGuildActivityHandler` en environnement `test`
 * — voir `config/services.yaml` — jamais sur l'alias global `PushSender`, que
 * `PushSenderWiringTest` prouve câblé vers le vrai `ExpoPushSender`.
 */
final class SpyingPushSender implements PushSender
{
    /** @var list<array{recipientId: Uuid, notification: PushNotification}> */
    public static array $sent = [];

    public function send(Uuid $userId, PushNotification $notification): array
    {
        self::$sent[] = ['recipientId' => $userId, 'notification' => $notification];

        return [];
    }

    public static function forget(): void
    {
        self::$sent = [];
    }
}
