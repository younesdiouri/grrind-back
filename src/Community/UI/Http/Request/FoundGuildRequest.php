<?php

declare(strict_types=1);

namespace App\Community\UI\Http\Request;

use App\Community\Domain\Guild;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Le nom, et rien d'autre. Pas de `founderId` : le joueur vient du jeton, sans quoi la
 * route permettrait de fonder une guilde au nom de quelqu'un d'autre.
 *
 * `normalizer: 'trim'` sur les deux contraintes : le clavier iOS ajoute volontiers une
 * espace après une saisie, et un nom qui ne serait qu'un blanc doit être refusé — pas
 * enregistré vide.
 */
final readonly class FoundGuildRequest
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: Guild::NAME_MAX_LENGTH, normalizer: 'trim')]
        public string $name = '',
    ) {
    }
}
