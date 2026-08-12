<?php

declare(strict_types=1);

namespace App\Training\Domain;

use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Activity\SessionSource;
use App\Shared\Domain\Activity\TrustLevel;
use App\Training\Domain\Exception\SessionNotActive;
use App\Training\Domain\Exception\SessionTooShort;
use App\Training\Infrastructure\Doctrine\TrainingSessionRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Une séance de sport, de son ouverture à sa clôture.
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
#[ORM\Entity(repositoryClass: TrainingSessionRepository::class)]
#[ORM\Table(name: 'training_session')]
#[ORM\Index(name: 'idx_training_session_user_started', columns: ['user_id', 'started_at'])]
// Prédicat écrit tel que PostgreSQL le **relit**, casts compris : `migrations:diff`
// compare deux chaînes, et `(status = 'ACTIVE')` reproposerait un DROP + CREATE
// identique à chaque diff. L'index — et non le contrôle applicatif — est ce qui
// garantit l'unicité : entre le SELECT et l'INSERT, deux requêtes passent.
#[ORM\UniqueConstraint(
    name: 'uniq_training_session_active',
    columns: ['user_id'],
    options: ['where' => "((status)::text = 'ACTIVE'::text)"],
)]
class TrainingSession
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
     * @throws SessionNotActive
     * @throws SessionTooShort
     */
    public function complete(DateTimeImmutable $now, TrainingRules $rules): void
    {
        $this->ensureActive();

        $elapsed = $this->elapsedAt($now);

        if ($elapsed < $rules->minimumDurationSeconds) {
            throw new SessionTooShort($this->id, $elapsed, $rules->minimumDurationSeconds);
        }

        $this->finish(SessionStatus::Completed, $now, $rules);
    }

    /**
     * La séance est close et ne rapportera rien, mais elle reste : on ne supprime pas
     * d'historique. Aucun plancher ici — c'est le pendant du refus opposé à une clôture
     * trop courte, il faut bien une sortie.
     *
     * @throws SessionNotActive
     */
    public function abandon(DateTimeImmutable $now, TrainingRules $rules): void
    {
        $this->ensureActive();
        $this->finish(SessionStatus::Abandoned, $now, $rules);
    }

    /**
     * Sous le plancher, la séance n'a pas eu lieu : un chrono lancé par erreur ne se
     * punit pas d'un quart d'heure. Au-dessus, complétée ou abandonnée, elle déclenche
     * l'attente — sinon abandonner deviendrait le moyen de l'effacer.
     */
    public function countsTowardCooldown(TrainingRules $rules): bool
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

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    private function finish(SessionStatus $outcome, DateTimeImmutable $now, TrainingRules $rules): void
    {
        $this->status = $outcome;
        $this->endedAt = $now;
        // Écrêtée, jamais rejetée. `durationSeconds` peut donc être plus petit que
        // `endedAt - startedAt`, et c'est lui qui fait foi : ce que la séance *vaut*.
        $this->durationSeconds = min($this->elapsedAt($now), $rules->maximumDurationSeconds);
    }

    /**
     * @throws SessionNotActive
     */
    private function ensureActive(): void
    {
        if (SessionStatus::Active !== $this->status) {
            throw new SessionNotActive($this->id, $this->status);
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
