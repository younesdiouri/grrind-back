<?php

declare(strict_types=1);

namespace App\Community\Domain;

use App\Shared\Domain\Alam\AlamRules;

/** La jauge la plus faible arbitre ; le surplus ne peut dépasser le plafond de chance publié. */
final readonly class AlamOutcome
{
    public function __construct(private AlamRules $rules)
    {
    }

    /**
     * @param array<string, int> $contributions
     * @param array<string, int> $targets */
    public function probabilityMillionths(array $contributions, array $targets): int
    {
        $minimum = 1.0;
        $surplus = 0.0;
        foreach ($targets as $key => $target) {
            $ratio = max(0, $contributions[$key] ?? 0) / $target;
            $minimum = min($minimum, $ratio);
            $surplus += max(0, $ratio - 1);
        }
        if ($minimum >= 1) {
            return 1000000;
        }
        $probability = min($this->rules->integer('rng_cap_permille') / 1000, $this->rules->integer('rng_base_permille') / 1000 * $minimum ** $this->rules->integer('deficit_exponent') * (1 + $this->rules->integer('surplus_weight_permille') / 1000 * log1p($surplus)));

        return (int) floor(1000000 * $probability);
    }

    /**
     * @param array<string, int> $contributions
     * @param array<string, int> $targets */
    public function progressionPermille(array $contributions, array $targets): int
    {
        $sum = 0.0;
        foreach ($targets as $key => $target) {
            $sum += min(1, max(0, $contributions[$key] ?? 0) / $target);
        }

        return (int) floor(1000 * $sum / \count($targets));
    }
}
