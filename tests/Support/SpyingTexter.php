<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Notifier\Message\MessageInterface;
use Symfony\Component\Notifier\Message\PushMessage;
use Symfony\Component\Notifier\Message\SentMessage;
use Symfony\Component\Notifier\Transport\TransportInterface;

/**
 * N'envoie rien nulle part — enregistre les `PushMessage` qu'on lui a demandé d'envoyer,
 * pour qu'un test (#144) inspecte la charge utile qu'`ExpoPushSender` a réellement
 * construite, sans reconstruire `ExpoTransport`.
 *
 * **`TransportInterface`, pas `TexterInterface` (#150).** `ExpoPushSender` consomme le
 * transport directement depuis ce ticket — voir son docblock pour pourquoi `Texter::send()`
 * ne pouvait pas garantir le `ticketId` que ce double doit pouvoir rendre.
 *
 * Une classe nommée plutôt qu'anonyme, même raison que {@see SpyingDeadPushTokens} :
 * `TransportInterface` ne porte pas `$sent`, et un facteur qui rendrait le type de
 * l'interface effacerait la propriété aux yeux de PHPStan à l'appel.
 */
final class SpyingTexter implements TransportInterface
{
    /** @var list<PushMessage> */
    public array $sent = [];

    public function __construct(
        private readonly ?string $messageId = null,
    ) {
    }

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

        $sentMessage = new SentMessage($message, 'spying');

        if (null !== $this->messageId) {
            $sentMessage->setMessageId($this->messageId);
        }

        return $sentMessage;
    }
}
