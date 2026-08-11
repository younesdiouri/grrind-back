<?php

declare(strict_types=1);

namespace App\Progression\Domain;

use App\Progression\Infrastructure\Doctrine\ProgressionSnapshotRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * L'état d'un joueur, prêt à être lu. **Un cache, jamais une vérité** : tout ce qu'il porte
 * se redéduit du ledger et de la courbe, et la commande de reconstruction (#20) le prouve
 * en le réécrivant à l'identique.
 *
 * Il existe parce que l'app iOS a besoin de l'état du joueur à l'ouverture sans rejouer
 * dix mille transactions, et parce que **c'est cette ligne qu'on verrouille** : une ligne
 * par joueur, donc un verrou pessimiste dessus sérialise les complétions concurrentes d'un
 * même compte sans bloquer qui que ce soit d'autre.
 *
 * Contrairement au ledger, il se met à jour — c'est même sa raison d'être. Ce qui ne doit
 * jamais arriver, c'est qu'on le corrige *à la main* : une divergence se répare en
 * reconstruisant, sinon on entérine le bug qui l'a produite.
 */
#[ORM\Entity(repositoryClass: ProgressionSnapshotRepository::class)]
#[ORM\Table(name: 'progression_snapshot')]
class ProgressionSnapshot
{
    /** L'identifiant du joueur *est* la clé : une ligne par compte, sans identifiant propre. */
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $userId;

    /** La somme du ledger à la dernière projection. Signé : une annulation peut le faire baisser. */
    #[ORM\Column]
    private int $totalXp;

    #[ORM\Column]
    private int $level;

    #[ORM\Column]
    private int $xpIntoLevel;

    /** `null` au niveau maximum — il n'y a plus de suivant, et zéro voudrait dire « atteint ». */
    #[ORM\Column(nullable: true)]
    private ?int $xpToNextLevel;

    /**
     * Les points **accordés** par les niveaux atteints, pas ceux qui restent à dépenser.
     * La distinction comptera au Lot 7 : les points dépensés se déduiront de l'arbre du
     * joueur (#32), et « disponibles » vaudra accordés − dépensés. Stocker le solde ici
     * rendrait le snapshot irreconstructible, puisque le ledger ne sait rien des dépenses.
     */
    #[ORM\Column]
    private int $earnedSkillPoints;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    private function __construct(Uuid $userId, int $totalXp, LevelCurve $curve, DateTimeImmutable $now)
    {
        $this->userId = $userId;
        $this->totalXp = $totalXp;
        $this->level = 0;
        $this->xpIntoLevel = 0;
        $this->xpToNextLevel = null;
        $this->earnedSkillPoints = 0;
        $this->updatedAt = $now;

        $this->project($curve);
    }

    /** Le joueur qui n'a encore rien fait : niveau 1, zéro XP. */
    public static function untouched(Uuid $userId, LevelCurve $curve, DateTimeImmutable $now): self
    {
        return new self($userId, 0, $curve, $now);
    }

    /**
     * Reprojette le snapshot sur un nouveau total, et rend **les niveaux franchis**.
     *
     * Plusieurs d'un coup est le cas normal, pas l'exception : une longue séance après une
     * pause peut en faire gagner deux ou trois, et le client a besoin de tous les animer.
     * La liste est vide quand rien ne bouge — et quand le total *baisse* : une annulation
     * ramène le joueur à son niveau réel, mais elle ne « fait pas descendre » un niveau au
     * sens du jeu, il n'y a rien à annoncer.
     *
     * @return list<int> les niveaux atteints, dans l'ordre
     */
    public function retotal(int $totalXp, LevelCurve $curve, DateTimeImmutable $now): array
    {
        $previous = $this->level;

        $this->totalXp = $totalXp;
        $this->updatedAt = $now;
        $this->project($curve);

        return $this->level > $previous ? range($previous + 1, $this->level) : [];
    }

    public function userId(): Uuid
    {
        return $this->userId;
    }

    public function totalXp(): int
    {
        return $this->totalXp;
    }

    public function level(): int
    {
        return $this->level;
    }

    public function xpIntoLevel(): int
    {
        return $this->xpIntoLevel;
    }

    public function xpToNextLevel(): ?int
    {
        return $this->xpToNextLevel;
    }

    public function earnedSkillPoints(): int
    {
        return $this->earnedSkillPoints;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Tout ce qui n'est pas le total se déduit de lui. Un seul endroit le fait. */
    private function project(LevelCurve $curve): void
    {
        $standing = $curve->standingAt($this->totalXp);

        $this->level = $standing->level;
        $this->xpIntoLevel = $standing->xpIntoLevel;
        $this->xpToNextLevel = $standing->xpToNextLevel;
        $this->earnedSkillPoints = $standing->earnedSkillPoints;
    }
}
