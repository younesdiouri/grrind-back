<?php

declare(strict_types=1);

namespace App\Community\Domain;

use App\Community\Infrastructure\Doctrine\PendingGuildActivityRepository;
use App\Shared\Domain\Activity\Discipline;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Ce qu'un joueur a accompli depuis la dernière annonce à sa guilde, en attente d'être
 * annoncé (#133) — jamais une notification déjà partie : {@see \App\Community\Application\AnnounceGuildActivityHandler}
 * la lit une seule fois puis la supprime. Sa présence *est* le signal qu'une annonce est
 * due.
 *
 * **Une ligne par auteur, jamais par séance.** C'est ce qui rend l'agrégation possible
 * sans horloge à surveiller : la première séance fraîche d'un joueur ouvre la ligne *et*
 * programme son annonce ({@see \App\Community\Application\GuildActivityNotifier}) ; toute
 * séance fraîche suivante, tant que l'annonce n'est pas encore partie, l'incrémente au
 * lieu d'en reprogrammer une seconde — voir
 * {@see PendingGuildActivityRepository::recordSession()}
 * pour comment le conflit d'insertion tranche entre les deux cas.
 */
#[ORM\Entity(repositoryClass: PendingGuildActivityRepository::class)]
#[ORM\Table(name: 'community_pending_guild_activity')]
class PendingGuildActivity
{
    /** L'auteur *est* la clé : une annonce en attente par compte, sans identifiant propre. */
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $authorId;

    #[ORM\Column]
    private int $sessionsCount;

    /** La somme des séances accumulées — jamais recalculée : chacune a déjà son propre montant, arbitré une fois par `Progression`. */
    #[ORM\Column]
    private int $totalXpGranted;

    /** La discipline de la séance la plus récente. Ne sert qu'au message détaillé d'une annonce à une seule séance — {@see \App\Community\Application\AnnounceGuildActivityHandler}. */
    #[ORM\Column(length: 16, enumType: Discipline::class)]
    private Discipline $lastDiscipline;

    /** La durée retenue de la séance la plus récente — même remarque que `lastDiscipline`. */
    #[ORM\Column]
    private int $lastDurationSeconds;

    /**
     * @internal ne se crée que par {@see PendingGuildActivityRepository::recordSession()},
     *           seul point qui sait distinguer une ligne neuve d'un conflit d'insertion
     */
    public function __construct(Uuid $authorId, Discipline $discipline, int $durationSeconds, int $xpGranted)
    {
        $this->authorId = $authorId;
        $this->sessionsCount = 1;
        $this->totalXpGranted = $xpGranted;
        $this->lastDiscipline = $discipline;
        $this->lastDurationSeconds = $durationSeconds;
    }

    public function authorId(): Uuid
    {
        return $this->authorId;
    }

    /** @internal voir {@see self::__construct()} */
    public function addSession(Discipline $discipline, int $durationSeconds, int $xpGranted): void
    {
        ++$this->sessionsCount;
        $this->totalXpGranted += $xpGranted;
        $this->lastDiscipline = $discipline;
        $this->lastDurationSeconds = $durationSeconds;
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
}
