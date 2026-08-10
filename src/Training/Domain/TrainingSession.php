<?php

declare(strict_types=1);

namespace App\Training\Domain;

use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Activity\SessionSource;
use App\Shared\Domain\Activity\TrustLevel;
use App\Training\Domain\Exception\SessionNotActive;
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
 * Ce qu'il ne fait pas, volontairement : il ne vérifie ni l'unicité de la séance
 * active, ni la durée plancher ou plafond, ni le cooldown. Ces garde-fous ont besoin
 * des *autres* séances du joueur et de la configuration de jeu ; ils s'appliquent une
 * couche au-dessus.
 */
#[ORM\Entity]
#[ORM\Table(name: 'training_session')]
#[ORM\Index(name: 'idx_training_session_user_started', columns: ['user_id', 'started_at'])]
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
     * @throws SessionNotActive
     */
    public function complete(DateTimeImmutable $now): void
    {
        $this->finish(SessionStatus::Completed, $now);
    }

    /**
     * Le joueur renonce. La séance est close et ne rapportera rien, mais elle reste :
     * on ne supprime pas d'historique, et sa durée servira à répondre à la question du
     * cooldown — abandonner ne doit pas devenir le moyen de l'effacer.
     *
     * @throws SessionNotActive
     */
    public function abandon(DateTimeImmutable $now): void
    {
        $this->finish(SessionStatus::Abandoned, $now);
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

    private function finish(SessionStatus $outcome, DateTimeImmutable $now): void
    {
        if (SessionStatus::Active !== $this->status) {
            throw new SessionNotActive($this->id, $this->status);
        }

        $this->status = $outcome;
        $this->endedAt = $now;
        // Un plancher à zéro : si l'horloge du serveur recule (un pas NTP suffit), la
        // séance ne rapporte rien plutôt que d'empoisonner le ledger d'une durée négative.
        $this->durationSeconds = max(0, $now->getTimestamp() - $this->startedAt->getTimestamp());
    }
}
