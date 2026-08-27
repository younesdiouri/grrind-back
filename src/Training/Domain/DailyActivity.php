<?php

declare(strict_types=1);

namespace App\Training\Domain;

use App\Shared\Domain\Activity\TrustLevel;
use App\Shared\Domain\Activity\WorkoutSource;
use App\Training\Infrastructure\Doctrine\DailyActivityRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * L'énergie active qu'un joueur a dépensée un jour donné — la moitié quotidienne de
 * Vitality (#165), à côté de la moitié « variété des sports » du #161.
 *
 * **Une révision, pas un nouveau fait.** Contrairement à {@see Workout}, qui naît complet
 * et ne change plus, une journée se corrige tant qu'elle n'est pas finie : 4 000 kcal à 14 h
 * puis 11 000 à 22 h ne sont pas deux journées, c'est la même relue plus tard. C'est ce que
 * l'`UPSERT (user, day)` de {@see DailyActivityRepository::upsert()} rend gratuit — voir son
 * docblock pour pourquoi rien de tout ça n'est append-only comme le ledger.
 *
 * **`day` est une date civile, pas un instant.** Le client l'envoie déjà situé dans le
 * fuseau du profil — c'est lui qui sait dans quel fuseau la montre a agrégé, exactement
 * comme pour le streak (voir `LocalDay`). Le serveur ne la recalcule pas depuis une borne
 * UTC : il n'y a pas de borne à recalculer, seulement un jour qu'on nous rapporte.
 *
 * `source` et `trust` reprennent le vocabulaire de {@see Workout} : toute activité est une
 * source attribuée, et ce n'est pas moins vrai d'une agrégation quotidienne que d'une
 * séance.
 */
#[ORM\Entity(repositoryClass: DailyActivityRepository::class)]
#[ORM\Table(name: 'daily_activity')]
#[ORM\UniqueConstraint(name: 'uniq_daily_activity_user_day', columns: ['user_id', 'day'])]
class DailyActivity
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $userId;

    /** Date civile, sans heure ni fuseau — voir le docblock de la classe. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $day;

    /**
     * `activeEnergyBurned` chez Apple, `ActiveCaloriesBurnedRecord` chez Health Connect —
     * la seule métrique de la V1 (#165). Jamais négative : une plateforme qui en rendrait
     * une aurait un bug, pas un joueur qui a brûlé moins que zéro calorie.
     */
    #[ORM\Column]
    private int $activeEnergyKcal;

    #[ORM\Column(length: 32, enumType: WorkoutSource::class)]
    private WorkoutSource $source;

    #[ORM\Column(length: 32, enumType: TrustLevel::class)]
    private TrustLevel $trust;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /** La dernière révision de cette journée — distincte de `createdAt`, qui ne bouge plus après la première écriture. */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $userId,
        DateTimeImmutable $day,
        int $activeEnergyKcal,
        WorkoutSource $source,
        DateTimeImmutable $now,
    ) {
        $this->id = Uuid::v7();
        $this->userId = $userId;
        $this->day = $day;
        $this->activeEnergyKcal = $activeEnergyKcal;
        $this->source = $source;
        $this->trust = $source->defaultTrust();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function userId(): Uuid
    {
        return $this->userId;
    }

    public function day(): DateTimeImmutable
    {
        return $this->day;
    }

    public function activeEnergyKcal(): int
    {
        return $this->activeEnergyKcal;
    }

    public function source(): WorkoutSource
    {
        return $this->source;
    }

    public function trust(): TrustLevel
    {
        return $this->trust;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
