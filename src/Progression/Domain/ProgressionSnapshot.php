<?php

declare(strict_types=1);

namespace App\Progression\Domain;

use App\Progression\Infrastructure\Doctrine\ProgressionSnapshotRepository;
use App\Shared\Domain\Activity\AttributeGains;
use App\Shared\Domain\Activity\Vitality;
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
 * Il existe parce que le client a besoin de l'état du joueur à l'ouverture sans rejouer
 * dix mille transactions, et parce que **c'est cette ligne qu'on verrouille** : une ligne
 * par joueur, donc un verrou pessimiste dessus sérialise les complétions concurrentes d'un
 * même compte sans bloquer qui que ce soit d'autre.
 *
 * Contrairement au ledger, il se met à jour — c'est même sa raison d'être. Ce qui ne doit
 * jamais arriver, c'est qu'on le corrige *à la main* : une divergence se répare en
 * reconstruisant, sinon on entérine le bug qui l'a produite.
 *
 * **Les quatre caractéristiques (#160) ne sont pas projetées par la courbe, contrairement
 * au palier.** Elles sont la simple somme du ledger —
 * {@see \App\Progression\Infrastructure\Doctrine\XpTransactionRepository::attributeTotalsOf()}
 * — recopiée telle quelle : rien à en déduire, `AttributeSplit` a déjà fait le calcul à
 * l'écriture.
 * Elles n'en sont pas moins un cache pour autant, et pas une cinquième vérité : c'est
 * toujours le ledger qui fait autorité, `retotal()` se contente de rejouer la même somme.
 *
 * **Vitality (#161), elle, *est* projetée — mais depuis les quatre caractéristiques
 * ci-dessus, jamais depuis le ledger.** Elle n'a pas de colonne à y relire : aucune
 * transaction ne lui est adressée, voir le docblock d'`App\Shared\Domain\Activity\Vitality`.
 * `project()` la calcule dans le même geste que le niveau, à partir de ce que `retotal()`
 * vient d'écrire dans `$this`.
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

    /**
     * Les quatre caractéristiques (#160), recopiées telles quelles du ledger — voir le
     * docblock de la classe. Signées comme `totalXp`, pour la même raison : une annulation
     * les fait redescendre, pas seulement le total.
     */
    #[ORM\Column]
    private int $strength;

    #[ORM\Column]
    private int $endurance;

    #[ORM\Column]
    private int $mobility;

    #[ORM\Column]
    private int $dexterity;

    /**
     * La cinquième caractéristique (#161), reprojetée comme le niveau l'est déjà — mais
     * depuis les quatre colonnes juste au-dessus, jamais depuis le ledger : `Vitality` ne
     * reçoit jamais d'écriture, il n'y a rien à y relire. Voir le docblock de la classe.
     */
    #[ORM\Column]
    private int $vitality;

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

    private function __construct(Uuid $userId, int $totalXp, AttributeGains $attributes, LevelCurve $curve, Vitality $vitality, DateTimeImmutable $now)
    {
        $this->userId = $userId;
        $this->totalXp = $totalXp;
        $this->strength = $attributes->strength;
        $this->endurance = $attributes->endurance;
        $this->mobility = $attributes->mobility;
        $this->dexterity = $attributes->dexterity;
        $this->level = 0;
        $this->xpIntoLevel = 0;
        $this->xpToNextLevel = null;
        $this->earnedSkillPoints = 0;
        $this->updatedAt = $now;

        $this->project($curve, $vitality);
    }

    /** Le joueur qui n'a encore rien fait : niveau 1, zéro XP, zéro partout — Vitality compris. */
    public static function untouched(Uuid $userId, LevelCurve $curve, Vitality $vitality, DateTimeImmutable $now): self
    {
        return new self($userId, 0, new AttributeGains(0, 0, 0, 0), $curve, $vitality, $now);
    }

    /**
     * Reprojette le snapshot sur un nouveau total et une nouvelle répartition, et rend
     * **les niveaux franchis**.
     *
     * **Les trois ensemble, dans le même appel.** Le total, les quatre caractéristiques et
     * la Vitality qu'on en dérive décrivent le même instant du ledger — les recevoir
     * séparément laisserait un appelant reprojeter l'un sans l'autre, et le snapshot
     * mentirait sur ses propres colonnes sans qu'aucun type ne s'en aperçoive (#160, #161).
     *
     * Plusieurs niveaux d'un coup est le cas normal, pas l'exception : une longue séance
     * après une pause peut en faire gagner deux ou trois, et le client a besoin de tous les
     * animer. La liste est vide quand rien ne bouge — et quand le total *baisse* : une
     * annulation ramène le joueur à son niveau réel, mais elle ne « fait pas descendre » un
     * niveau au sens du jeu, il n'y a rien à annoncer.
     *
     * @return list<int> les niveaux atteints, dans l'ordre
     */
    public function retotal(int $totalXp, AttributeGains $attributes, LevelCurve $curve, Vitality $vitality, DateTimeImmutable $now): array
    {
        $previous = $this->level;

        $this->totalXp = $totalXp;
        $this->strength = $attributes->strength;
        $this->endurance = $attributes->endurance;
        $this->mobility = $attributes->mobility;
        $this->dexterity = $attributes->dexterity;
        $this->updatedAt = $now;
        $this->project($curve, $vitality);

        return $this->level > $previous ? range($previous + 1, $this->level) : [];
    }

    public function userId(): Uuid
    {
        return $this->userId;
    }

    /**
     * Où en est le joueur, **d'un seul geste**.
     *
     * Les quatre champs se lisent aussi un à un, et c'est ce qu'on faisait. Mais qui veut
     * le point de départ d'une animation les veut *tous les quatre au même instant* : lus
     * séparément autour d'un {@see retotal}, deux d'entre eux décriraient le palier de
     * départ et deux celui d'arrivée, sans qu'aucun type ne s'en aperçoive.
     */
    public function standing(): LevelStanding
    {
        return new LevelStanding($this->level, $this->xpIntoLevel, $this->xpToNextLevel, $this->earnedSkillPoints);
    }

    /**
     * Les quatre caractéristiques, **d'un seul geste** — même raison qu'à {@see standing()} :
     * lues séparément autour d'un {@see retotal}, elles pourraient mélanger un instant
     * d'avant et un instant d'après.
     */
    public function attributes(): AttributeGains
    {
        return new AttributeGains($this->strength, $this->endurance, $this->mobility, $this->dexterity);
    }

    public function vitality(): int
    {
        return $this->vitality;
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

    /**
     * Tout ce qui n'est pas le total ou les quatre caractéristiques se déduit d'eux. Un
     * seul endroit le fait — Vitality y compris : elle se dérive des colonnes juste
     * écrites ci-dessus, jamais du ledger directement, voir le docblock de la classe.
     */
    private function project(LevelCurve $curve, Vitality $vitality): void
    {
        $standing = $curve->standingAt($this->totalXp);

        $this->level = $standing->level;
        $this->xpIntoLevel = $standing->xpIntoLevel;
        $this->xpToNextLevel = $standing->xpToNextLevel;
        $this->earnedSkillPoints = $standing->earnedSkillPoints;
        $this->vitality = $vitality->of($this->attributes());
    }
}
