<?php

declare(strict_types=1);

namespace App\Training\Domain\Together;

use InvalidArgumentException;

final readonly class TogetherRules
{
    // Pilote autorisé au #329 : couverture et trous restent à mesurer sur appareils réels.
    public const array PILOT = ['maximum_players' => 4, 'overlap_percent' => 80, 'distance_meters' => 100, 'coverage_percent' => 80, 'maximum_gap_seconds' => 60, 'maximum_accuracy_meters' => 50, 'sample_seconds' => 15];

    /** @param array<string, mixed> $values */
    public function __construct(public array $values)
    {
        foreach (self::PILOT as $key => $default) {
            if (!\is_int($values[$key] ?? null) || $values[$key] < 1 || $values[$key] > 10000) {
                throw new InvalidArgumentException('Réglage Sport ensemble invalide : '.$key);
            }
        }
        if ($this->get('maximum_players') > 4 || $this->get('overlap_percent') > 100 || $this->get('coverage_percent') > 100 || $this->get('sample_seconds') > $this->get('maximum_gap_seconds')) {
            throw new InvalidArgumentException('Seuils Sport ensemble incohérents.');
        }
    }

    public function get(string $key): int
    {
        $value = $this->values[$key];
        \assert(\is_int($value));

        return $value;
    }
}
