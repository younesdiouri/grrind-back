<?php

declare(strict_types=1);

namespace App\Training\Application;

use App\Shared\Domain\Activity\Discipline;
use Symfony\Component\Uid\Uuid;

/**
 * Qui, et quoi. Le *quand* n'est pas un paramètre : il vient de l'horloge serveur, et
 * c'est ce qui rend l'antidatage impossible.
 */
final readonly class StartSession
{
    public function __construct(
        public Uuid $userId,
        public Discipline $discipline,
    ) {
    }
}
