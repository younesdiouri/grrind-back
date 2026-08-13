<?php

declare(strict_types=1);

namespace App\Progression\Domain;

use App\Progression\Infrastructure\Doctrine\XpTransactionRepository;
use App\Shared\Domain\Activity\Discipline;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Une écriture au ledger d'XP. **C'est la vérité** : le niveau et le total du joueur en
 * sont des projections, jamais l'inverse. On n'incrémente pas un compteur `xp` en place —
 * un compteur ne sait pas dire d'où il vient, et une erreur de calcul y devient définitive.
 *
 * **Append-only.** Aucun mutateur, aucune méthode qui change quoi que ce soit après la
 * construction, et `LedgerIsAppendOnly` refuse les `UPDATE` et `DELETE` que Doctrine
 * pourrait produire. Une séance invalidée ne s'efface pas : elle produit une transaction
 * négative (voir {@see reversalOf()}), et les deux restent.
 *
 * **Le montant n'est pas une donnée d'entrée.** Il est la somme du breakdown, calculée
 * ici. Un total qui pourrait diverger du détail qui l'explique serait le premier endroit
 * où le ledger cesse d'être vérifiable.
 *
 * **La version du ruleset est celle du calcul, pas celle d'aujourd'hui.** C'est ce qui
 * permet de rééquilibrer sans corrompre l'historique : une transaction dit ce qu'elle
 * valait sous les règles qui l'ont produite.
 */
#[ORM\Entity(repositoryClass: XpTransactionRepository::class)]
#[ORM\Table(name: 'xp_transaction')]
// La journée d'un joueur : c'est l'index des garde-fous quotidiens, lus à chaque workout
// importé, et celui de la reconstruction du snapshot (#20).
#[ORM\Index(name: 'idx_xp_transaction_user_occurred', columns: ['user_id', 'occurred_at'])]
// L'historique paginé (#19), qui se lit « les écritures d'un joueur, les plus récentes
// d'abord » et pagine sur l'identifiant. Un second index sur la même table plutôt qu'un
// tri sur celui du dessus : `ORDER BY id DESC` ne sait pas s'en servir, et remonter la clé
// primaire en filtrant sur le compte ferait payer à un joueur inactif tout ce que les
// autres ont écrit depuis. La table est écrite quelques fois par jour et par joueur —
// c'est le bon côté du compromis pour la payer en lecture.
#[ORM\Index(name: 'idx_xp_transaction_user_id', columns: ['user_id', 'id'])]
// L'idempotence du ledger, garantie par la base et non par un SELECT préalable : entre le
// contrôle et l'écriture, deux complétions rejouées par un client mobile passent toutes
// les deux. Le couple (source, raison) autorise ce qu'il faut et rien de plus — une séance
// se crédite une fois, s'invalide une fois.
#[ORM\UniqueConstraint(name: 'uniq_xp_transaction_source_reason', columns: ['source_id', 'reason'])]
class XpTransaction
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $userId;

    /** Entier signé. Jamais un flottant : une valeur de jeu persistée ne s'arrondit pas. */
    #[ORM\Column]
    private int $amount;

    #[ORM\Column(length: 32, enumType: XpReason::class)]
    private XpReason $reason;

    /**
     * Ce qui a produit l'écriture — l'identifiant de la séance en v1. Pas de clé
     * étrangère : `Training` est un autre module, et Deptrac interdit à `Progression` d'en
     * connaître les tables autant que les classes.
     */
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $sourceId;

    /**
     * La discipline et la durée que cette écriture valorise. Dénormalisées ici plutôt que
     * relues chez `Training` : ce sont les deux entrées des garde-fous quotidiens
     * (rendements décroissants sur le temps cumulé, plafond d'XP par discipline), et
     * `Progression` doit pouvoir y répondre sans franchir une frontière de module.
     *
     * Elles rendent surtout le ledger auto-suffisant : « à cette date, pour cette
     * discipline, cette durée, tu as reçu tant sous ces règles » est ce qu'une piste
     * d'audit doit savoir dire seule.
     */
    #[ORM\Column(length: 32, enumType: Discipline::class)]
    private Discipline $discipline;

    /**
     * **Signée**, comme le montant. L'annulation d'une séance porte une durée négative :
     * les deux compteurs de la journée se soldent alors par simple somme, sans que la
     * requête ait à connaître les raisons — une séance invalidée cesse de peser sur les
     * rendements décroissants exactement comme elle cesse de compter en XP.
     */
    #[ORM\Column]
    private int $durationSeconds;

    #[ORM\Column(length: 32)]
    private string $rulesetVersion;

    /**
     * @var Collection<int, XpTransactionLine>
     */
    #[ORM\OneToMany(targetEntity: XpTransactionLine::class, mappedBy: 'transaction', cascade: ['persist'])]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $lines;

    /**
     * **L'instant du sport, pas celui de l'écriture.** C'est ce qui rattache une écriture à
     * une journée, et donc ce que lisent les garde-fous quotidiens : rendements décroissants
     * sur le temps cumulé, plafond d'XP par discipline.
     *
     * La distinction n'existait pas tant que Grrind tenait le chronomètre — on créditait au
     * moment où la séance se fermait. Avec l'import, dix workouts vieux de dix jours
     * arrivent à la même seconde : les dater de leur écriture les entasserait tous sur la
     * journée de l'import, où le plafond quotidien en écraserait neuf. Le joueur qui
     * synchronise une fois par semaine perdrait sa semaine.
     *
     * L'instant de l'écriture n'est pas perdu pour autant : l'identifiant est un UUID v7, il
     * l'encode. C'est une des raisons de ce choix, et c'est ici qu'elle sert.
     */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $occurredAt;

    private function __construct(
        Uuid $userId,
        XpReason $reason,
        Uuid $sourceId,
        Discipline $discipline,
        int $durationSeconds,
        XpBreakdown $breakdown,
        string $rulesetVersion,
        DateTimeImmutable $occurredAt,
    ) {
        $this->id = Uuid::v7();
        $this->userId = $userId;
        $this->reason = $reason;
        $this->sourceId = $sourceId;
        $this->discipline = $discipline;
        $this->durationSeconds = $durationSeconds;
        $this->rulesetVersion = $rulesetVersion;
        $this->amount = $breakdown->total();
        $this->occurredAt = $occurredAt;
        $this->lines = new ArrayCollection();

        foreach ($breakdown->lines as $position => $line) {
            $this->lines->add(new XpTransactionLine($this, $position, $line));
        }
    }

    /**
     * L'XP accordée pour un workout. Le total peut valoir zéro — une séance entièrement
     * rognée par les rendements décroissants reste une séance, et le breakdown est
     * précisément ce qui le fait comprendre.
     *
     * `$occurredAt` est l'instant du **sport**, pas celui de l'appel : c'est lui qui range
     * l'écriture dans une journée. Voir la propriété du même nom.
     */
    public static function creditFor(
        Uuid $userId,
        Uuid $sessionId,
        Discipline $discipline,
        int $durationSeconds,
        XpAward $award,
        DateTimeImmutable $occurredAt,
    ): self {
        return new self(
            $userId,
            XpReason::SessionCompleted,
            $sessionId,
            $discipline,
            $durationSeconds,
            $award->breakdown,
            $award->rulesetVersion,
            $occurredAt,
        );
    }

    /**
     * La contrepartie exacte d'un crédit, ligne par ligne. Elle reprend la
     * `rulesetVersion` de l'écriture annulée et non celle du jour : on rend ce qu'on avait
     * donné, sous les règles qui l'avaient donné. Recalculer aux règles courantes ferait
     * d'un rééquilibrage une redistribution silencieuse.
     *
     * Elle reprend aussi son `occurredAt`, et il n'y a pas d'instant à passer : une
     * annulation doit se poser sur **la journée qu'elle annule**, sinon la somme du jour ne
     * se solde plus. Une séance de mardi invalidée jeudi rendrait l'XP de mardi tout en
     * creusant le compteur de jeudi — deux jours faux au lieu d'aucun. L'instant de
     * l'annulation reste lisible dans l'UUID v7 de la ligne.
     */
    public static function reversalOf(self $credit): self
    {
        return new self(
            $credit->userId,
            XpReason::SessionInvalidated,
            $credit->sourceId,
            $credit->discipline,
            // Négative, comme le montant : la séance annulée cesse de peser sur les
            // rendements décroissants de sa journée exactement comme elle cesse de compter
            // en XP, et la somme du jour s'en charge sans cas particulier.
            -$credit->durationSeconds,
            $credit->breakdown()->negated(),
            $credit->rulesetVersion,
            $credit->occurredAt,
        );
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function userId(): Uuid
    {
        return $this->userId;
    }

    public function amount(): int
    {
        return $this->amount;
    }

    public function reason(): XpReason
    {
        return $this->reason;
    }

    public function sourceId(): Uuid
    {
        return $this->sourceId;
    }

    public function discipline(): Discipline
    {
        return $this->discipline;
    }

    public function durationSeconds(): int
    {
        return $this->durationSeconds;
    }

    public function rulesetVersion(): string
    {
        return $this->rulesetVersion;
    }

    /** Les lignes persistées redeviennent la valeur pure que le calcul avait produite. */
    public function breakdown(): XpBreakdown
    {
        return new XpBreakdown(...array_map(
            static fn (XpTransactionLine $line): XpBreakdownLine => $line->toBreakdownLine(),
            $this->lines->toArray(),
        ));
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
