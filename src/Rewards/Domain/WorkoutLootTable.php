<?php

declare(strict_types=1);

namespace App\Rewards\Domain;

use App\Shared\Domain\Activity\Discipline;
use InvalidArgumentException;

/**
 * Une table de tirage d'origine séance, gardée par une éligibilité — discipline, durée,
 * niveau — voir le docblock de {@see LootTables}. Le tirage lui-même (#28) choisira parmi
 * toutes les tables dont {@see isEligibleFor()} répond vrai ; cette classe ne fait que
 * poser la question, elle ne tranche pas laquelle gagne s'il y en a plusieurs.
 */
final readonly class WorkoutLootTable
{
    /**
     * @param list<Discipline> $disciplines liste vide = toute discipline
     */
    public function __construct(
        public string $key,
        public array $disciplines,
        public int $minimumDurationMinutes,
        public int $minimumLevel,
        public LootTable $table,
    ) {
        if ($this->minimumDurationMinutes < 0) {
            throw new InvalidArgumentException(\sprintf('"%s" a une durée minimale négative : %d.', $this->key, $this->minimumDurationMinutes));
        }

        if ($this->minimumLevel < 1) {
            throw new InvalidArgumentException(\sprintf('"%s" a un niveau minimal sous 1 : %d.', $this->key, $this->minimumLevel));
        }
    }

    public function isEligibleFor(Discipline $discipline, int $durationMinutes, int $level): bool
    {
        return ([] === $this->disciplines || \in_array($discipline, $this->disciplines, true))
            && $durationMinutes >= $this->minimumDurationMinutes
            && $level >= $this->minimumLevel;
    }
}
