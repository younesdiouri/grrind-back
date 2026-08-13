<?php

declare(strict_types=1);

namespace App\Training\Domain;

use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Activity\SessionSource;
use App\Shared\Domain\Activity\TrustLevel;
use App\Training\Infrastructure\Doctrine\WorkoutRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Une séance de sport **déjà faite**, telle que la montre l'a enregistrée.
 *
 * Il n'y a plus de transition d'état : un workout naît complet. Ce n'était pas le cas
 * tant que Grrind portait son propre chronomètre — on ouvrait, on fermait, et l'agrégat
 * avait un statut. L'import santé supprime l'ouverture : quand le fait arrive, il est
 * passé.
 *
 * **Le serveur n'a plus l'horloge, il l'arbitre.** Les bornes viennent du fournisseur —
 * c'est lui qui était au poignet — mais la durée n'est pas un champ qu'on recopie : elle
 * se calcule ici à partir des deux bornes. Un client qui enverrait `duration: 36000` avec
 * une séance d'un quart d'heure ne serait pas cru, et il n'y a aucune raison de lui
 * laisser cette prise.
 *
 * Ce que le workout **vaut** — plancher, écrêtage, chevauchements, fenêtre d'antériorité —
 * ne se décide pas ici mais à l'import (#91) : ces règles ont besoin des *autres* workouts
 * du joueur, que l'agrégat ne voit pas.
 */
#[ORM\Entity(repositoryClass: WorkoutRepository::class)]
#[ORM\Table(name: 'workout')]
#[ORM\Index(name: 'idx_workout_user_started', columns: ['user_id', 'started_at'])]
// Le double crédit ne se prévient pas dans le code : entre le SELECT qui cherche
// l'`externalId` et l'INSERT qui l'écrit, deux synchronisations concurrentes passent
// toutes les deux. C'est la base qui refuse la seconde.
//
// Partiel, parce qu'une source sans identifiant fournisseur reste possible et que
// PostgreSQL considère deux NULL comme distincts : une contrainte totale sur une
// colonne nullable n'interdirait rien. Prédicat écrit tel que PostgreSQL le relit.
#[ORM\UniqueConstraint(
    name: 'uniq_workout_external',
    columns: ['user_id', 'source', 'external_id'],
    options: ['where' => '(external_id IS NOT NULL)'],
)]
class Workout
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $userId;

    #[ORM\Column(length: 32, enumType: Discipline::class)]
    private Discipline $discipline;

    #[ORM\Column(length: 32, enumType: SessionSource::class)]
    private SessionSource $source;

    #[ORM\Column(length: 32, enumType: TrustLevel::class)]
    private TrustLevel $trust;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $endedAt;

    /**
     * Figée à l'écriture, pas recalculée à la lecture : elle alimente le ledger d'XP, et
     * une valeur qui se recalcule peut changer après coup — un fuseau, un arrondi, une
     * correction de borne, et l'historique ne dit plus ce qu'il a payé.
     */
    #[ORM\Column]
    private int $durationSeconds;

    /**
     * Ce que la montre a mesuré. **Toutes nullables, et c'est structurel** : aucun
     * appareil ne fournit tout — pas de cardio sur un modèle d'entrée de gamme, pas de
     * distance sur un vélo d'appartement, pas de dénivelé sur un tapis. Le modèle ne
     * peut pas exiger ce que le matériel ne mesure pas.
     *
     * L'absence est donc « non mesuré », jamais zéro, et le calcul d'XP (#90) doit la
     * traiter comme « pas de bonus ». Un `0` en base voudra dire « mesuré, et nul » —
     * un tour de piste plat a bien un dénivelé de zéro.
     *
     * Des entiers, jamais de flottant sur une valeur de jeu persistée : mètres et
     * battements par minute. Le kilomètre décimal n'existe qu'à l'affichage.
     *
     * Les calories et la fréquence cardiaque n'entrent dans aucun calcul aujourd'hui.
     * Elles sont stockées quand même parce qu'elles ne sont **pas rattrapables** :
     * Apple ne les redonnera pas pour un workout déjà importé, et une décision de game
     * design dans six mois ne peut pas ressusciter un historique qu'on n'a pas écrit.
     */
    #[ORM\Column(nullable: true)]
    private ?int $distanceMeters = null;

    #[ORM\Column(nullable: true)]
    private ?int $calories = null;

    #[ORM\Column(nullable: true)]
    private ?int $elevationGainMeters = null;

    #[ORM\Column(nullable: true)]
    private ?int $averageHeartRate = null;

    /**
     * L'identifiant du workout chez le fournisseur : `HKWorkout.uuid` côté Apple,
     * `metadata.id` côté Health Connect. Il porte toute la protection contre le double
     * crédit — voir `uniq_workout_external` sur la classe.
     *
     * Nul tant que la source n'en fournit pas. Personne ne l'écrit encore : l'import
     * qui le remplit arrive au #88.
     */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $externalId = null;

    /** `startedAt` est un fait sportif, `createdAt` un fait système. Ils divergent
     * dès qu'une activité s'importe après coup, c'est-à-dire toujours. */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    private function __construct(
        Uuid $userId,
        Discipline $discipline,
        SessionSource $source,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $endedAt,
        DateTimeImmutable $now,
    ) {
        $this->id = Uuid::v7();
        $this->userId = $userId;
        $this->discipline = $discipline;
        $this->source = $source;
        $this->trust = $source->defaultTrust();
        $this->startedAt = $startedAt;
        $this->endedAt = $endedAt;
        // Plancher à zéro : une montre qui rend des bornes inversées — un fuseau mal
        // appliqué suffit — donne un workout sans valeur, pas une durée négative qui
        // empoisonnerait le ledger.
        $this->durationSeconds = max(0, $endedAt->getTimestamp() - $startedAt->getTimestamp());
        $this->createdAt = $now;
    }

    /**
     * Un fait rapporté par une source, écrit tel quel.
     *
     * `$now` est l'heure du serveur et ne sert qu'à `createdAt` : le reste vient du
     * fournisseur. C'est exactement la frontière entre ce qu'on constate et ce qu'on
     * date nous-mêmes.
     */
    public static function record(
        Uuid $userId,
        Discipline $discipline,
        SessionSource $source,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $endedAt,
        DateTimeImmutable $now,
    ): self {
        return new self($userId, $discipline, $source, $startedAt, $endedAt, $now);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function userId(): Uuid
    {
        return $this->userId;
    }

    public function discipline(): Discipline
    {
        return $this->discipline;
    }

    public function source(): SessionSource
    {
        return $this->source;
    }

    public function trust(): TrustLevel
    {
        return $this->trust;
    }

    public function startedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function endedAt(): DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function durationSeconds(): int
    {
        return $this->durationSeconds;
    }

    public function distanceMeters(): ?int
    {
        return $this->distanceMeters;
    }

    public function calories(): ?int
    {
        return $this->calories;
    }

    public function elevationGainMeters(): ?int
    {
        return $this->elevationGainMeters;
    }

    public function averageHeartRate(): ?int
    {
        return $this->averageHeartRate;
    }

    public function externalId(): ?string
    {
        return $this->externalId;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
