<?php

declare(strict_types=1);

namespace App\Shared\Domain\Notification;

use App\Shared\Domain\Activity\Discipline;
use App\Shared\Infrastructure\Doctrine\PendingSessionCreditRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Tombeau persisté #255 des fenêtres auteur créées par #252. Cette entité reste mappée jusqu'au
 * drainage de Messenger, pour que `AnnounceSessionCredit` historique puisse la refermer sans
 * jamais produire de push ; le #256 porte sa suppression définitive.
 */
#[ORM\Entity(repositoryClass: PendingSessionCreditRepository::class)]
#[ORM\Table(name: 'shared_pending_session_credit')]
class PendingSessionCredit
{
    /** Le joueur *est* la clé : une notification en attente par compte, sans identifiant propre — c'est aussi le destinataire, il n'y en a pas d'autre. */
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $playerId;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $windowId;

    /** Jamais réécrit par {@see self::addSession()} — c'est l'ouverture de la fenêtre, pas sa dernière activité, qui mesure son abandon. */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $openedAt;

    #[ORM\Column]
    private int $sessionsCount;

    /** La somme des séances accumulées — jamais recalculée : chacune a déjà son propre montant, arbitré une fois par `Progression`. */
    #[ORM\Column]
    private int $totalXpGranted;

    /** La discipline de la séance la plus récente. Ne sert qu'au message détaillé d'une fenêtre à une seule séance — {@see \App\Shared\Application\AnnounceSessionCreditHandler}. */
    #[ORM\Column(length: 16, enumType: Discipline::class)]
    private Discipline $lastDiscipline;

    /** La durée retenue de la séance la plus récente — même remarque que `lastDiscipline`. */
    #[ORM\Column]
    private int $lastDurationSeconds;

    /** Le palier d'où le joueur partait à l'ouverture de la fenêtre — jamais réécrit. */
    #[ORM\Column]
    private int $initialLevel;

    /** Le palier après la séance la plus récente de la fenêtre — mis à jour à chaque {@see self::addSession()}. */
    #[ORM\Column]
    private int $currentLevel;

    /**
     * @internal ne se crée que par {@see PendingSessionCreditRepository::recordSession()},
     *           seul point qui sait distinguer une ligne neuve d'un conflit d'insertion
     */
    public function __construct(
        Uuid $playerId,
        Uuid $windowId,
        DateTimeImmutable $openedAt,
        Discipline $discipline,
        int $durationSeconds,
        int $xpGranted,
        int $levelBefore,
        int $levelAfter,
    ) {
        $this->playerId = $playerId;
        $this->windowId = $windowId;
        $this->openedAt = $openedAt;
        $this->sessionsCount = 1;
        $this->totalXpGranted = $xpGranted;
        $this->lastDiscipline = $discipline;
        $this->lastDurationSeconds = $durationSeconds;
        $this->initialLevel = $levelBefore;
        $this->currentLevel = $levelAfter;
    }

    public function playerId(): Uuid
    {
        return $this->playerId;
    }

    public function windowId(): Uuid
    {
        return $this->windowId;
    }

    public function openedAt(): DateTimeImmutable
    {
        return $this->openedAt;
    }

    /** @internal voir {@see self::__construct()} */
    public function addSession(Discipline $discipline, int $durationSeconds, int $xpGranted, int $levelAfter): void
    {
        ++$this->sessionsCount;
        $this->totalXpGranted += $xpGranted;
        $this->lastDiscipline = $discipline;
        $this->lastDurationSeconds = $durationSeconds;
        $this->currentLevel = $levelAfter;
    }

    public function sessionsCount(): int
    {
        return $this->sessionsCount;
    }

    public function totalXpGranted(): int
    {
        return $this->totalXpGranted;
    }

    public function lastDiscipline(): Discipline
    {
        return $this->lastDiscipline;
    }

    public function lastDurationSeconds(): int
    {
        return $this->lastDurationSeconds;
    }

    /** `true` si la fenêtre entière a franchi au moins un niveau, du premier palier constaté à aujourd'hui — jamais un palier intermédiaire. */
    public function leveledUp(): bool
    {
        return $this->currentLevel > $this->initialLevel;
    }

    public function currentLevel(): int
    {
        return $this->currentLevel;
    }
}
