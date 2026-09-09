<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\Exception\GuildNotFound;
use App\Community\Domain\GuildMessage;
use App\Community\Infrastructure\Doctrine\GuildMessageRepository;
use App\Community\Infrastructure\Doctrine\GuildRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/** Le verrou partagé avec les adhésions sérialise l'envoi, son idempotence et le départ. */
final readonly class SendGuildMessage
{
    public function __construct(private GuildRepository $guilds, private GuildMessageRepository $messages, private MessageBusInterface $bus, private ClockInterface $clock)
    {
    }

    public function send(Uuid $guildId, Uuid $authorId, Uuid $clientId, string $text): GuildMessage
    {
        return $this->guilds->transactional(function () use ($guildId, $authorId, $clientId, $text): GuildMessage {
            $guild = $this->guilds->lockForUpdate($guildId);
            if (null === $guild || !$guild->hasMember($authorId)) {
                throw new GuildNotFound();
            }
            $fingerprint = hash('sha256', $text);
            $existing = $this->messages->replay($guild, $authorId, $clientId);
            if (null !== $existing) {
                if ($existing->fingerprint() !== $fingerprint) {
                    throw new ConflictHttpException('Cet identifiant client désigne déjà un autre message.');
                }

                return $existing;
            }
            $message = new GuildMessage($guild, $authorId, $clientId, $this->messages->nextPosition($guild), $text, $fingerprint, $this->clock->now());
            $this->messages->add($message);
            $this->bus->dispatch(new ChatChanged($guildId->toRfc4122()));

            return $message;
        });
    }
}
