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
 * Ce qu'un joueur a accompli depuis sa dernière notification « Bien joué ! » (#252), en
 * attente d'être annoncé — le pendant, pour l'auteur lui-même, de
 * {@see \App\Community\Domain\PendingGuildActivity} pour ses co-équipiers. Même geste
 * d'agrégation, pour la même raison : un import de dix séances publie dix
 * `WorkoutCredited`, et dix pushes seraient exactement l'échec que le #133 a déjà refermé
 * côté guilde — la réutiliser plutôt qu'en écrire une seconde n'aurait pas été possible,
 * `PendingGuildActivity` vit dans `Community` et le destinataire y est un membre de guilde,
 * jamais l'auteur ; ici le destinataire *est* l'auteur, et rien dans ce module n'a besoin de
 * connaître une guilde pour l'annoncer. D'où une classe séparée, dans `Shared`, plutôt qu'un
 * port vers `Community` que Deptrac refuserait de toute façon (aucun module ne dépend d'un
 * autre module métier).
 *
 * **Une ligne par joueur, jamais par séance** — même geste que `PendingGuildActivity`, voir
 * son docblock pour ce que ça achète : la première séance fraîche ouvre la ligne *et*
 * programme l'envoi ({@see \App\Shared\Application\SessionCreditedNotifier}), toute séance
 * fraîche suivante tant que l'envoi n'est pas encore parti l'incrémente au lieu d'en
 * reprogrammer un second.
 *
 * **`windowId` et `openedAt` existent pour la même idempotence du #134**, portée ici par
 * {@see NotificationAttempt} comme pour la guilde : un rejeu de l'envoi doit retomber sur
 * la même fenêtre, et une fenêtre abandonnée (l'unique tentative épuisée côté
 * `messenger.yaml`) doit pouvoir repartir sans jamais renotifier ce qui l'a déjà été.
 *
 * **`initialLevel`/`currentLevel`, propres à cette classe.** `PendingGuildActivity` n'a
 * jamais eu besoin de savoir si un palier a été franchi — les co-équipiers ne voient que la
 * séance, pas le niveau. Ici, le ticket demande que le niveau franchi apparaisse « s'il
 * tient » : `initialLevel` est le palier d'où le joueur partait à l'ouverture de la fenêtre,
 * jamais réécrit ; `currentLevel` est celui d'après la séance la plus récente, mis à jour à
 * chaque {@see self::addSession()}. Le message ne compare que les deux bornes de la
 * fenêtre, jamais un palier intermédiaire — un joueur qui franchit deux niveaux dans le même
 * import voit « Niveau N+2 », pas deux mentions.
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
