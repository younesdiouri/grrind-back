<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Storage;

use App\Community\Application\ChatImageStorage;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Lock\LockFactory;

/**
 * La base fait foi, y compris après une dissolution en cascade ou un arrêt brutal.
 * Le même verrou que l'envoi couvre écriture du fichier puis COMMIT : aucun délai
 * arbitraire ne peut transformer une transaction lente en suppression de son image.
 * Une panne de base interrompt le nettoyage ; elle ne signifie jamais « aucune référence ».
 */
final readonly class CleanupChatImages
{
    public function __construct(private ChatImageStorage $images, private Connection $connection, #[Target('chat_storage')] private LockFactory $storageLocks)
    {
    }

    public function clean(): int
    {
        $lock = $this->storageLocks->createLock('chat-images');
        $lock->acquire(true);
        $count = 0;
        try {
            foreach ($this->images->keys() as $key) {
                if (false === $this->connection->fetchOne('SELECT 1 FROM community_guild_message WHERE image_key = ?', [$key])) {
                    $this->images->delete($key);
                    ++$count;
                }
            }
        } finally {
            $lock->release();
        }

        return $count;
    }
}
