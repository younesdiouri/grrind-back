<?php

declare(strict_types=1);

namespace App\Training\Application;

use App\Shared\Domain\Activity\Discipline;
use Symfony\Component\Uid\Uuid;

/**
 * Tout ce que le serveur accepte pour ouvrir une séance : qui, et quoi. Le *quand*
 * n'est pas un paramètre — il vient de l'horloge serveur, et c'est ce qui rend
 * l'antidatage impossible.
 *
 * L'auteur est un `Uuid` et non un `User` : `Training` ne connaît pas `Identity`.
 * L'identifiant de sécurité étant l'UUID du compte, le contrôleur n'a rien à traduire.
 */
final readonly class StartSession
{
    public function __construct(
        public Uuid $userId,
        public Discipline $discipline,
    ) {
    }
}
