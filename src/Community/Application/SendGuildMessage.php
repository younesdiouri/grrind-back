<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\Exception\GuildNotFound;
use App\Community\Domain\GuildMessage;
use App\Community\Infrastructure\Doctrine\GuildMessageRepository;
use App\Community\Infrastructure\Doctrine\GuildRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/** Le verrou partagé avec les adhésions sérialise l'envoi, son idempotence et le départ. */
final readonly class SendGuildMessage
{
    public function __construct(
        private GuildRepository $guilds,
        private GuildMessageRepository $messages,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private ChatImageStorage $images,
        #[Target('chat_storage')]
        private LockFactory $storageLocks,
    ) {
    }

    public function send(Uuid $guildId, Uuid $authorId, Uuid $clientId, string $text, ?PreparedChatImage $image = null): GuildMessage
    {
        $lock = $this->storageLocks->createLock('chat-images');
        // Le nettoyage attend le COMMIT : un fichier en cours d'envoi n'est pas orphelin.
        $lock->acquire(true);
        try {
            return $this->guilds->transactional(function () use ($guildId, $authorId, $clientId, $text, $image): GuildMessage {
                $guild = $this->guilds->lockForUpdate($guildId);
                if (null === $guild || !$guild->hasMember($authorId)) {
                    throw new GuildNotFound();
                }
                $fingerprint = hash('sha256', json_encode([$text, $image?->fingerprint], \JSON_THROW_ON_ERROR));
                $existing = $this->messages->replay($guild, $authorId, $clientId);
                if (null !== $existing) {
                    if ($existing->fingerprint() !== $fingerprint) {
                        throw new ConflictHttpException('Cet identifiant client désigne déjà un autre message.');
                    }

                    return $existing;
                }
                $key = null === $image ? null : $this->images->store($image->contents);
                $message = new GuildMessage($guild, $authorId, $clientId, $this->messages->nextPosition($guild), $text, $fingerprint, $this->clock->now(), $key);
                $this->messages->add($message);
                $this->bus->dispatch(new ChatChanged($guildId->toRfc4122()));

                return $message;
            });
        } finally {
            $lock->release();
        }
    }
}
