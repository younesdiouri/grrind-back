<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Notifier\Message\MessageInterface;
use Symfony\Component\Notifier\Message\PushMessage;
use Symfony\Component\Notifier\Message\SentMessage;
use Symfony\Component\Notifier\TexterInterface;

/**
 * N'envoie rien nulle part — enregistre les `PushMessage` qu'on lui a demandé d'envoyer,
 * pour qu'un test (#144) inspecte la charge utile qu'`ExpoPushSender` a réellement
 * construite, sans reconstruire `ExpoTransport`.
 *
 * Une classe nommée plutôt qu'anonyme, même raison que {@see SpyingDeadPushTokens} :
 * `TexterInterface` ne porte pas `$sent`, et un facteur qui rendrait le type de
 * l'interface effacerait la propriété aux yeux de PHPStan à l'appel.
 */
final class SpyingTexter implements TexterInterface
{
    /** @var list<PushMessage> */
    public array $sent = [];

    public function __toString(): string
    {
        return 'spying';
    }

    public function supports(MessageInterface $message): bool
    {
        return $message instanceof PushMessage;
    }

    public function send(MessageInterface $message): SentMessage
    {
        if ($message instanceof PushMessage) {
            $this->sent[] = $message;
        }

        return new SentMessage($message, 'spying');
    }
}
