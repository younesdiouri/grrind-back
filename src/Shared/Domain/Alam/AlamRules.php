<?php

declare(strict_types=1);

namespace App\Shared\Domain\Alam;

use DateTimeZone;
use InvalidArgumentException;

/** Règles publiées communes au calendrier sportif et au raid ; aucune valeur de balance implicite. */
final readonly class AlamRules
{
    /** @param array<string, mixed> $values */
    public function __construct(public array $values)
    {
        foreach (['strength_target', 'endurance_target', 'mobility_target', 'dexterity_target', 'vitality_target', 'presentation_seconds', 'memory_limit', 'resource_base', 'activity_cap_permille', 'deficit_exponent'] as $key) {
            if (!\is_int($values[$key] ?? null) || $values[$key] < 1) {
                throw new InvalidArgumentException('Réglage Alam positif requis : '.$key);
            }
        }
        foreach (['rng_cap_permille', 'rng_base_permille', 'surplus_weight_permille', 'equipment_chance_permille', 'legendary_chance_permille', 'resource_progression'] as $key) {
            if (!\is_int($values[$key] ?? null) || $values[$key] < 0 || $values[$key] > 1000) {
                throw new InvalidArgumentException('Réglage Alam hors limites : '.$key);
            }
        }
        foreach (['start_hour', 'reset_hour'] as $key) {
            if (!\is_int($values[$key] ?? null) || $values[$key] < 0 || $values[$key] > 23) {
                throw new InvalidArgumentException('Heure Alam invalide : '.$key);
            }
        }
        if ($this->integer('reset_hour') <= $this->integer('start_hour')) {
            throw new InvalidArgumentException('Le reset doit suivre le raid dans la même journée.');
        }
        foreach (['timezone', 'resource_key', 'enemy_key'] as $key) {
            if (!\is_string($values[$key] ?? null) || '' === $values[$key]) {
                throw new InvalidArgumentException('Réglage Alam texte requis : '.$key);
            }
        }
        if ($this->integer('presentation_seconds') < 12 || $this->integer('presentation_seconds') > 3600 || $this->integer('memory_limit') > 100) {
            throw new InvalidArgumentException('Présentation Alam entre 12 et 3600 secondes, mémoire au plus 100 éditions.');
        }
        new DateTimeZone($this->text('timezone'));
        $thresholds = $values['encounter_thresholds'] ?? null;
        if (!\is_array($thresholds) || 3 !== \count($thresholds) || !array_is_list($thresholds)) {
            throw new InvalidArgumentException('Alam exige trois rencontres.');
        }
        $previous = 0;
        foreach ($thresholds as $threshold) {
            if (!\is_int($threshold) || $threshold <= $previous || $threshold > 1000) {
                throw new InvalidArgumentException('Seuils Alam strictement croissants entre 1 et 1000.');
            }
            $previous = $threshold;
        }
        if (1000 !== $previous) {
            throw new InvalidArgumentException('La dernière rencontre utilise la cible entière.');
        }
    }

    public function integer(string $key): int
    {
        $value = $this->values[$key];
        \assert(\is_int($value));

        return $value;
    }

    public function text(string $key): string
    {
        $value = $this->values[$key];
        \assert(\is_string($value));

        return $value;
    }

    /** @return list<int> */
    public function thresholds(): array
    {
        /** @var list<int> */
        return $this->values['encounter_thresholds'];
    }

    /** @return array<string, int> */
    public function targets(int $activeCount, int $threshold = 1000): array
    {
        $targets = [];
        foreach (['strength', 'endurance', 'mobility', 'dexterity', 'vitality'] as $attribute) {
            $targets[$attribute] = max(1, (int) ceil($this->integer($attribute.'_target') * max(1, $activeCount) * $threshold / 1000));
        }

        return $targets;
    }
}
