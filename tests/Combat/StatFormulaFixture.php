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
        ];
    }
}
