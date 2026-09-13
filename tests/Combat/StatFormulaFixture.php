<?php

declare(strict_types=1);

namespace App\Tests\Combat;

/** Valeurs de référence indépendantes pour prouver la parité avec les anciens calculs. */
final class StatFormulaFixture
{
    /** @return array<string, array{source: string, combination: string, secondary: ?string}> */
    public static function data(): array
    {
        return [
            'hp' => ['source' => 'vitality', 'combination' => 'single', 'secondary' => null],
            'damage' => ['source' => 'strength', 'combination' => 'single', 'secondary' => null],
            'maintenance' => ['source' => 'endurance', 'combination' => 'single', 'secondary' => null],
            'dodge' => ['source' => 'mobility', 'combination' => 'single', 'secondary' => null],
            'critical_chance' => ['source' => 'dexterity', 'combination' => 'single', 'secondary' => null],
            'mitigation' => ['source' => 'strength', 'combination' => 'geometric_mean', 'secondary' => 'endurance'],
            'guard' => ['source' => 'strength', 'combination' => 'geometric_mean', 'secondary' => 'mobility'],
            'critical_resistance' => ['source' => 'strength', 'combination' => 'geometric_mean', 'secondary' => 'dexterity'],
            'cooldown_reduction' => ['source' => 'endurance', 'combination' => 'geometric_mean', 'secondary' => 'mobility'],
            'combo' => ['source' => 'endurance', 'combination' => 'geometric_mean', 'secondary' => 'dexterity'],
            'precision' => ['source' => 'mobility', 'combination' => 'geometric_mean', 'secondary' => 'dexterity'],
        ];
    }
}
