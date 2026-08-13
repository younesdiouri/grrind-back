<?php

declare(strict_types=1);

namespace App\Training\Domain;

use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Activity\SessionSource;
use App\Shared\Domain\Activity\TrustLevel;
use App\Training\Domain\Exception\WorkoutNotActive;
use App\Training\Domain\Exception\WorkoutTooShort;
use App\Training\Infrastructure\Doctrine\WorkoutRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Une séance de sport, de son ouverture à sa clôture.
 *
 * Le nom dit « workout » alors que l'agrégat s'ouvre et se ferme encore : c'est
 * volontaire. Le renommage arrive avant le virage vers l'import santé (#85) parce qu'il
 * n'a aucun effet, et qu'un renommage sans effet se relit. Les transitions `start()`,
 * `abandon()` et le cooldown, eux, disparaîtront avec le chronomètre.
 *
 * **Le serveur possède l'horloge.** L'agrégat ne la lit jamais lui-même : chaque
 * transition reçoit le `$now` de l'appelant. Aucun timestamp du client n'entre dans le
 * calcul de la durée, sinon l'antidatage est une ligne de JSON.
 *
 * Répartition des garde-fous : ceux qui ne concernent *que cette séance* — plancher,
 * plafond — sont ici, parce qu'ils définissent une clôture valide et que les laisser
 * au-dessus reviendrait à espérer que personne n'oublie de les appeler. Ceux qui ont
 * besoin des *autres* séances du joueur — unicité de l'active, cooldown — sont une
 * couche au-dessus.
 */
#[ORM\Entity(repositoryClass: WorkoutRepository::class)]
#[ORM\Table(name: 'workout')]
#[ORM\Index(name: 'idx_workout_user_started', columns: ['user_id', 'started_at'])]
// Prédicat écrit tel que PostgreSQL le **relit**, casts compris : `migrations:diff`
// compare deux chaînes, et `(status = 'ACTIVE')` reproposerait un DROP + CREATE
// identique à chaque diff. L'index — et non le contrôle applicatif — est ce qui
// garantit l'unicité : entre le SELECT et l'INSERT, deux requêtes passent.
#[ORM\UniqueConstraint(
    name: 'uniq_workout_active',
    columns: ['user_id'],
    options: ['where' => "((status)::text = 'ACTIVE'::text)"],
)]
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

    #[ORM\Column(length: 16, enumType: SessionStatus::class)]
    private SessionStatus $status;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $endedAt = null;

    /**
     * Figée à la clôture, pas recalculée à la lecture : elle alimentera le ledger d'XP,
     * et une valeur qui se recalcule peut changer après coup.
     */
    #[ORM\Column(nullable: true)]
    private ?int $durationSeconds = null;

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

    /** `startedAt` est un fait sportif, `createdAt` un fait système. Ils divergeront
     * quand une activité s'importera après coup. */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    private function __construct(
        Uuid $userId,
        Discipline $discipline,
        SessionSource $source,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $now,
    ) {
        $this->id = Uuid::v7();
        $this->userId = $userId;
        $this->discipline = $discipline;
        $this->source = $source;
        $this->trust = $source->defaultTrust();
        $this->status = SessionStatus::Active;
        $this->startedAt = $startedAt;
        $this->createdAt = $now;
    }

    /** Le client n'envoie que la discipline ; le serveur date. */
    public static function start(Uuid $userId, Discipline $discipline, DateTimeImmutable $now): self
    {
        return new self($userId, $discipline, SessionSource::ManualTimer, $now, $now);
    }

    /**
     * Seule voie soumise à la durée plancher : sous le seuil, rien n'est écrit et la
     * séance reste en cours — le joueur continue, ou renonce par `abandon()`.
     *
     * Ce qu'elle rapporte ne se décide pas ici : `Progression` le calcule, dans la même
     * transaction, derrière le port `SessionRewards`.
     *
     * @throws WorkoutNotActive
     * @throws WorkoutTooShort
     */
    public function complete(DateTimeImmutable $now, WorkoutRules $rules): void
    {
        $this->ensureActive();

        $elapsed = $this->elapsedAt($now);

        if ($elapsed < $rules->minimumDurationSeconds) {
            throw new WorkoutTooShort($this->id, $elapsed, $rules->minimumDurationSeconds);
        }

        $this->finish(SessionStatus::Completed, $now, $rules);
    }

    /**
     * La séance est close et ne rapportera rien, mais elle reste : on ne supprime pas
     * d'historique. Aucun plancher ici — c'est le pendant du refus opposé à une clôture
     * trop courte, il faut bien une sortie.
     *
     * @throws WorkoutNotActive
     */
    public function abandon(DateTimeImmutable $now, WorkoutRules $rules): void
    {
        $this->ensureActive();
        $this->finish(SessionStatus::Abandoned, $now, $rules);
    }

    /**
     * Sous le plancher, la séance n'a pas eu lieu : un chrono lancé par erreur ne se
     * punit pas d'un quart d'heure. Au-dessus, complétée ou abandonnée, elle déclenche
     * l'attente — sinon abandonner deviendrait le moyen de l'effacer.
     */
    public function countsTowardCooldown(WorkoutRules $rules): bool
    {
        return null !== $this->durationSeconds && $this->durationSeconds >= $rules->minimumDurationSeconds;
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

    public function status(): SessionStatus
    {
        return $this->status;
    }

    public function startedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function endedAt(): ?DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function durationSeconds(): ?int
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

    private function finish(SessionStatus $outcome, DateTimeImmutable $now, WorkoutRules $rules): void
    {
        $this->status = $outcome;
        $this->endedAt = $now;
        // Écrêtée, jamais rejetée. `durationSeconds` peut donc être plus petit que
        // `endedAt - startedAt`, et c'est lui qui fait foi : ce que la séance *vaut*.
        $this->durationSeconds = min($this->elapsedAt($now), $rules->maximumDurationSeconds);
    }

    /**
     * @throws WorkoutNotActive
     */
    private function ensureActive(): void
    {
        if (SessionStatus::Active !== $this->status) {
            throw new WorkoutNotActive($this->id, $this->status);
        }
    }

    /**
     * Plancher à zéro : si l'horloge recule (un pas NTP suffit), la séance ne rapporte
     * rien plutôt que d'empoisonner le ledger d'une durée négative.
     */
    private function elapsedAt(DateTimeImmutable $now): int
    {
        return max(0, $now->getTimestamp() - $this->startedAt->getTimestamp());
    }
}
