<?php

declare(strict_types=1);

namespace App\Training\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Le joueur renonce à sa séance. Comme pour la clôture, le serveur n'accepte que deux
 * choses : qui, et laquelle. Le *quand* est à lui, et le motif de l'abandon ne l'intéresse
 * pas — rien dans le jeu n'en dépend.
 */
final readonly class AbandonSession
{
    public function __construct(
        public Uuid $userId,
        public Uuid $sessionId,
    ) {
    }
}
