<?php

declare(strict_types=1);

namespace App\Combat\Domain;

use InvalidArgumentException;

/**
 * Aucun code saisi n'est exécuté. Les moyennes sont arrondies vers le bas et la somme
 * refuse un dépassement ; la moyenne arithmétique ne forme jamais la somme intermédiaire.
 */
final readonly class StatFormula
{
    public function __construct(public StatSource $source, public StatCombination $combination, public ?StatSource $secondary = null)
    {
        if ((StatCombination::Single === $combination && null !== $secondary) || (StatCombination::Single !== $combination && (null === $secondary || $source === $secondary))) {
            throw new InvalidArgumentException('Une combinaison exige deux attributs distincts ; une source simple n’en accepte qu’un.');
        }
    }

    /** @param array<string, int> $attributes */
    public function score(array $attributes): int
    {
        $a = $attributes[$this->source->value] ?? throw new InvalidArgumentException('Attribut source absent.');
        $b = null === $this->secondary ? 0 : ($attributes[$this->secondary->value] ?? throw new InvalidArgumentException('Second attribut absent.'));
        if ($a < 0 || $b < 0) {
            throw new InvalidArgumentException('Les attributs effectifs doivent être positifs ou nuls.');
        }

        return match ($this->combination) {
            StatCombination::Single => $a,
            StatCombination::Sum => CombatMath::add($a, $b),
            StatCombination::ArithmeticMean => intdiv($a, 2) + intdiv($b, 2) + intdiv($a % 2 + $b % 2, 2),
            StatCombination::GeometricMean => CombatMath::pair($a, $b),
        };
    }

    /**
     * @param array<string, array{source: string, combination: string, secondary: ?string}> $definitions
     * @param array<string, int>                                                            $attributes
     *
     * @return array<string, int>
     */
    public static function scores(array $definitions, array $attributes): array
    {
        if (\count($definitions) !== \count(DerivedStat::cases())) {
            throw new InvalidArgumentException('Une formule est requise pour chacune des onze statistiques.');
        }
        $scores = [];
        foreach (DerivedStat::cases() as $stat) {
            $definition = $definitions[$stat->value] ?? throw new InvalidArgumentException('Formule manquante : '.$stat->value);
            $scores[$stat->value] = self::fromArray($definition)->score($attributes);
        }

        return $scores;
    }

    /** @param array{source: string, combination: string, secondary: ?string} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            StatSource::tryFrom($data['source']) ?? throw new InvalidArgumentException('Attribut source inconnu.'),
            StatCombination::tryFrom($data['combination']) ?? throw new InvalidArgumentException('Combinaison inconnue.'),
            null === $data['secondary'] ? null : (StatSource::tryFrom($data['secondary']) ?? throw new InvalidArgumentException('Second attribut inconnu.')),
        );
    }
}
