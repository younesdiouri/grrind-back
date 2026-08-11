<?php

declare(strict_types=1);

namespace App\Progression\Domain;

use App\Progression\Infrastructure\Doctrine\XpTransactionRepository;
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
// L'historique se lit toujours « les écritures d'un joueur, les plus récentes d'abord » :
// c'est l'index de GET /api/progression/history (#19) comme de la reconstruction du
// snapshot (#20).
#[ORM\Index(name: 'idx_xp_transaction_user_created', columns: ['user_id', 'created_at'])]
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

    #[ORM\Column(length: 32)]
    private string $rulesetVersion;

    /**
     * @var Collection<int, XpTransactionLine>
     */
    #[ORM\OneToMany(targetEntity: XpTransactionLine::class, mappedBy: 'transaction', cascade: ['persist'])]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $lines;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    private function __construct(
        Uuid $userId,
        XpReason $reason,
        Uuid $sourceId,
        XpBreakdown $breakdown,
        string $rulesetVersion,
        DateTimeImmutable $now,
    ) {
        $this->id = Uuid::v7();
        $this->userId = $userId;
        $this->reason = $reason;
        $this->sourceId = $sourceId;
        $this->rulesetVersion = $rulesetVersion;
        $this->amount = $breakdown->total();
        $this->createdAt = $now;
        $this->lines = new ArrayCollection();

        foreach ($breakdown->lines as $position => $line) {
            $this->lines->add(new XpTransactionLine($this, $position, $line));
        }
    }

    /**
     * L'XP accordée pour une séance close. Le total peut valoir zéro — une séance
     * entièrement rognée par les rendements décroissants reste une séance, et le
     * breakdown est précisément ce qui le fait comprendre.
     */
    public static function creditFor(
        Uuid $userId,
        Uuid $sessionId,
        XpBreakdown $breakdown,
        string $rulesetVersion,
        DateTimeImmutable $now,
    ): self {
        return new self($userId, XpReason::SessionCompleted, $sessionId, $breakdown, $rulesetVersion, $now);
    }

    /**
     * La contrepartie exacte d'un crédit, ligne par ligne. Elle reprend la
     * `rulesetVersion` de l'écriture annulée et non celle du jour : on rend ce qu'on avait
     * donné, sous les règles qui l'avaient donné. Recalculer aux règles courantes ferait
     * d'un rééquilibrage une redistribution silencieuse.
     */
    public static function reversalOf(self $credit, DateTimeImmutable $now): self
    {
        return new self(
            $credit->userId,
            XpReason::SessionInvalidated,
            $credit->sourceId,
            $credit->breakdown()->negated(),
            $credit->rulesetVersion,
            $now,
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

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
