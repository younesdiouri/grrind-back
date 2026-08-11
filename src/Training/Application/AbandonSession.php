<?php

declare(strict_types=1);

namespace App\Training\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Qui, et laquelle. Le motif de l'abandon n'est pas demandé : rien dans le jeu n'en
 * dépend.
 */
final readonly class AbandonSession
{
    public function __construct(
        public Uuid $userId,
        public Uuid $sessionId,
    ) {
    }
}
