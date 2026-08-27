<?php

declare(strict_types=1);

namespace App\Training\UI\Http\Request;

use App\Shared\Domain\Activity\WorkoutSource;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une journée telle que le client la rapporte : une date civile, pas un instant.
 *
 * **`day` se dénormalise au format `AAAA-MM-JJ`, sans heure ni fuseau** — voir le docblock
 * de `DailyActivity` pour pourquoi. `#[Context]` force ce format côté Serializer ; le format
 * par défaut du composant (RFC 3339, avec heure) refuserait la chaîne que le client envoie
 * réellement (source :
 * https://symfony.com/doc/current/components/serializer.html#the-datetimenormalizer).
 *
 * `activeEnergyKcal` porte une borne haute large, dans le même esprit que
 * `averageHeartRate` d'`ImportedWorkoutRequest` : un garde-fou contre une unité mal
 * convertie (des joules envoyées comme des kilocalories, par exemple), pas une opinion sur
 * ce qu'une journée humaine peut brûler.
 */
final readonly class DailyActivityEntryRequest
{
    public const int MAX_ACTIVE_ENERGY_KCAL = 20_000;

    public function __construct(
        #[Assert\NotNull]
        #[Context([DateTimeNormalizer::FORMAT_KEY => 'Y-m-d'])]
        public ?DateTimeImmutable $day = null,
        #[Assert\NotNull]
        #[Assert\Range(min: 0, max: self::MAX_ACTIVE_ENERGY_KCAL)]
        public ?int $activeEnergyKcal = null,
        #[Assert\NotNull]
        public ?WorkoutSource $source = null,
    ) {
    }
}
