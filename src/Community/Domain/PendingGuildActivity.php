<?php

declare(strict_types=1);

namespace App\Community\Domain;

use App\Community\Infrastructure\Doctrine\PendingGuildActivityRepository;
use App\Shared\Domain\Activity\Discipline;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Ce qu'un joueur a accompli depuis la dernière annonce à sa guilde, en attente d'être
 * annoncé (#133) — jamais une notification déjà partie : {@see \App\Community\Application\AnnounceGuildActivityHandler}
 * la lit, l'annonce, puis la referme. Sa présence *est* le signal qu'une annonce est due
 * ou reste due.
 *
 * **Une ligne par auteur, jamais par séance.** C'est ce qui rend l'agrégation possible
 * sans horloge à surveiller : la première séance fraîche d'un joueur ouvre la ligne *et*
 * programme son annonce ({@see \App\Community\Application\GuildActivityNotifier}) ; toute
 * séance fraîche suivante, tant que l'annonce n'est pas encore partie, l'incrémente au
 * lieu d'en reprogrammer une seconde — voir
 * {@see PendingGuildActivityRepository::recordSession()}
 * pour comment le conflit d'insertion tranche entre les deux cas.
 *
 * **`windowId` existe pour le #134, pas pour ce ticket-ci.** L'auteur est la clé de
 * stockage, mais deux fenêtres successives du même auteur (mode dégradé, voir
 * {@see \App\Community\Application\AnnounceGuildActivity}) partagent cet auteur sans être
 * la même annonce : sans un identifiant propre à *cette* fenêtre, un
 * {@see \App\Community\Application\AnnounceGuildActivityHandler} qui rejoue après qu'une
 * seconde fenêtre a déjà pris la place de la première toucherait les données de la
 * mauvaise fenêtre — ou, pire, la trace de livraison du #134 confondrait les deux et
 * rendrait la seconde annonce muette. Généré une fois à l'ouverture, jamais réécrit par
 * {@see self::addSession()}.
 *
 * **`openedAt` existe pour la même raison, un cran plus loin (#134).** Depuis que
 * {@see PendingGuildActivityRepository::close()} ne referme plus la ligne en entrant dans
 * le handler mais après avoir essayé tous les destinataires, un handler qui échoue sur ses
 * trois tentatives (parti sur le transport `failed`) laisse la ligne ouverte pour de bon :
 * plus rien ne la referme, et sans recours, plus aucune séance suivante de cet auteur ne
 * déclencherait d'annonce — `recordSession()` la trouverait toujours présente et
 * conclurait à chaque fois « une annonce est déjà en vol ». `openedAt` est ce que
 * `recordSession()` compare à `notifications.yaml` (`stale_window_minutes`) pour
 * distinguer une fenêtre réellement en vol d'une fenêtre abandonnée, et reprogrammer cette
 * dernière plutôt que de condamner son auteur au silence.
 */
#[ORM\Entity(repositoryClass: PendingGuildActivityRepository::class)]
#[ORM\Table(name: 'community_pending_guild_activity')]
class PendingGuildActivity
{
    /** L'auteur *est* la clé : une annonce en attente par compte, sans identifiant propre. */
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $authorId;

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
    public function __construct(Uuid $authorId, Uuid $windowId, DateTimeImmutable $openedAt, Discipline $discipline, int $durationSeconds, int $xpGranted)
    {
        $this->authorId = $authorId;
        $this->windowId = $windowId;
        $this->openedAt = $openedAt;
        $this->sessionsCount = 1;
        $this->totalXpGranted = $xpGranted;
        $this->lastDiscipline = $discipline;
        $this->lastDurationSeconds = $durationSeconds;
    }

    public function authorId(): Uuid
    {
        return $this->authorId;
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
