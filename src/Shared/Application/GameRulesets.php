<?php

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Le point de lecture atomique de la configuration publiée. La forme reste scalaire pour
 * que Shared ne dépende d'aucun module de jeu ; chaque module reconstruit ses objets domaine.
 */
interface GameRulesets
{
    /** @return array<string, mixed> */
    public function snapshot(): array;

    public function version(): string;

    public function revision(): int;
}
