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
 * **Le serveur possède l'horloge.** L'agrégat ne lit jamais l'heure lui-même : chaque
 * transition reçoit le `$now` de l'appelant, qui le tient de `ClockInterface`. Aucun
 * timestamp venu du client n'entre dans le calcul de la durée, sinon l'antidatage est
 * une ligne de JSON. C'est aussi ce qui rend le domaine testable sans infrastructure.
 *
 * L'agrégat ne connaît pas `User` — Deptrac interdit l'import croisé, et il n'en a
 * pas besoin : il porte un `userId`, point. La cohérence de cet UUID relève de
 * l'authentification, pas d'une clé étrangère.
 *
 * Les garde-fous qui ne concernent *que cette séance* sont ici — durée plancher, durée
 * plafond — parce qu'ils sont la définition même d'une clôture valide, et que les
 * laisser au-dessus reviendrait à espérer que personne n'oublie de les appeler. Ceux
 * qui ont besoin des *autres* séances du joueur — unicité de la séance active,
 * cooldown — restent une couche au-dessus : l'agrégat ne connaît que lui-même.
 *
 * L'index unique partiel n'est pas une redite de la vérification applicative : entre
 * le `SELECT` qui ne trouve aucune séance active et l'`INSERT` qui en crée une, deux
 * requêtes simultanées passent toutes les deux. Le contrôle applicatif rend une erreur
 * lisible dans le cas courant ; l'index est ce qui garantit l'invariant.
 */
#[ORM\Entity(repositoryClass: TrainingSessionRepository::class)]
#[ORM\Table(name: 'training_session')]
#[ORM\Index(name: 'idx_training_session_user_started', columns: ['user_id', 'started_at'])]
// Le prédicat est écrit tel que PostgreSQL le **relit**, casts compris, et non tel
// qu'on l'écrirait à la main : `doctrine:migrations:diff` compare deux chaînes, et
// `(status = 'ACTIVE')` proposerait un DROP + CREATE identique à chaque diff.
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
     * Figée à la clôture plutôt que recalculée à la lecture : c'est elle qui alimentera
     * le ledger d'XP, et une valeur qui se recalcule est une valeur qui peut changer
     * après coup. Un entier, jamais un flottant — la règle vaut pour toute valeur de
     * jeu persistée.
     */
    #[ORM\Column(nullable: true)]
    private ?int $durationSeconds = null;

    /**
     * Distinct de `startedAt`, et pas par excès de zèle : les deux coïncident pour un
     * chronomètre lancé dans l'app, mais une activité importée plus tard aura commencé
     * bien avant d'être enregistrée. `startedAt` est un fait sportif, `createdAt` un
     * fait système.
     */
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

    /**
     * Ouvre une séance au chronomètre : le joueur appuie sur « démarrer », le serveur
     * date. Le client n'envoie que la discipline, et c'est tout ce qu'on accepte de lui.
     */
    public static function start(Uuid $userId, Discipline $discipline, DateTimeImmutable $now): self
    {
        return new self($userId, $discipline, SessionSource::ManualTimer, $now, $now);
    }

    /**
     * La séance est finie et compte. Ce qu'elle rapporte ne se décide pas ici : le
     * calcul d'XP appartient à `Progression`, qui apprendra la nouvelle par événement.
     *
     * Seule voie soumise à la durée plancher : sous le seuil, rien n'est écrit et la
     * séance reste en cours. Le joueur continue, ou renonce par `abandon()`.
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
     * Le joueur renonce. La séance est close et ne rapportera rien, mais elle reste :
     * on ne supprime pas d'historique, et sa durée servira à répondre à la question du
     * cooldown — abandonner ne doit pas devenir le moyen de l'effacer.
     *
     * Aucun plancher ici, et c'est le pendant du refus opposé à une clôture trop
     * courte : il faut bien une sortie, sans quoi une séance ouverte par erreur
     * enfermerait le joueur jusqu'au plafond de durée.
     *
     * @throws SessionNotActive
     */
    public function abandon(DateTimeImmutable $now, TrainingRules $rules): void
    {
        $this->ensureActive();
        $this->finish(SessionStatus::Abandoned, $now, $rules);
    }

    /**
     * Une séance qui compte pour le cooldown. Sous le plancher, elle n'a pas eu lieu :
     * c'est un chronomètre lancé par erreur, et le punir d'un quart d'heure d'attente
     * ferait du garde-fou une punition. Au-dessus, complétée ou abandonnée, elle
     * déclenche l'attente — sinon abandonner deviendrait le moyen de l'effacer.
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
        // Écrêtée, jamais rejetée : le joueur qui oublie de couper son chrono garde le
        // plafond au lieu de tout perdre. La durée retenue peut donc être plus courte
        // que `endedAt - startedAt`, et c'est elle qui fait foi — `durationSeconds` est
        // ce que la séance *vaut*, pas ce que la montre a affiché.
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
     * Un plancher à zéro : si l'horloge du serveur recule (un pas NTP suffit), la
     * séance ne rapporte rien plutôt que d'empoisonner le ledger d'une durée négative.
     */
    private function elapsedAt(DateTimeImmutable $now): int
    {
        return max(0, $now->getTimestamp() - $this->startedAt->getTimestamp());
    }
}
