<?php

declare(strict_types=1);

namespace App\Progression\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Une ligne du détail de calcul, telle qu'elle est stockée.
 *
 * Table fille et non colonne JSON : la source et le montant sont deux colonnes typées, que
 * PostgreSQL refuse de laisser dériver, et « combien d'XP ce joueur doit-il à son streak »
 * devient un `GROUP BY` au lieu d'une relecture de tout l'historique.
 *
 * `position` porte l'ordre d'animation. S'en remettre à l'ordre d'insertion marcherait
 * jusqu'au jour où il ne marcherait plus, sans que rien ne le signale.
 *
 * Append-only comme sa transaction, et construite par elle seule : rien d'autre ne doit
 * pouvoir ajouter une ligne à une écriture déjà passée.
 */
#[ORM\Entity]
#[ORM\Table(name: 'xp_transaction_line')]
#[ORM\UniqueConstraint(name: 'uniq_xp_transaction_line_position', columns: ['transaction_id', 'position'])]
// Doctrine indexe toujours la colonne de jointure, sans voir que l'index unique
// ci-dessus la couvre déjà en tête. Le déclarer ici lui donne au moins un nom lisible
// plutôt que `IDX_3176EFCA2FC0CB0F`, et le diff reste vide.
#[ORM\Index(name: 'idx_xp_transaction_line_transaction', columns: ['transaction_id'])]
class XpTransactionLine
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: XpTransaction::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'transaction_id', nullable: false, onDelete: 'RESTRICT')]
    private XpTransaction $transaction;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(length: 32, enumType: XpBreakdownSource::class)]
    private XpBreakdownSource $source;

    #[ORM\Column]
    private int $amount;

    /** @internal construite par {@see XpTransaction}, qui est seule à savoir quand une écriture est complète */
    public function __construct(XpTransaction $transaction, int $position, XpBreakdownLine $line)
    {
        $this->id = Uuid::v7();
        $this->transaction = $transaction;
        $this->position = $position;
        $this->source = $line->source;
        $this->amount = $line->amount;
    }

    public function toBreakdownLine(): XpBreakdownLine
    {
        return new XpBreakdownLine($this->source, $this->amount);
    }

    public function position(): int
    {
        return $this->position;
    }
}
