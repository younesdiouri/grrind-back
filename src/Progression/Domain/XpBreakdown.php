<?php

declare(strict_types=1);

namespace App\Progression\Domain;

use InvalidArgumentException;

/**
 * Le détail d'un calcul d'XP : une suite ordonnée de contributions dont la somme *est* le
 * montant accordé. Valeur pure — c'est ce que `XpCalculator` (#14) rendra, et ce que le
 * ledger matérialise en lignes de `xp_transaction_line`.
 *
 * **L'ordre est le contrat.** Le client iOS anime les lignes les unes après les autres :
 * il ne les trie pas, il les joue. C'est pour ça que la persistance porte une `position`
 * plutôt que de s'en remettre à l'ordre d'insertion.
 */
final readonly class XpBreakdown
{
    /** @var list<XpBreakdownLine> */
    public array $lines;

    public function __construct(XpBreakdownLine ...$lines)
    {
        // Un montant sans explication n'est pas un montant : le joueur doit pouvoir
        // savoir d'où viennent ses points, y compris quand il n'en a gagné aucun.
        if ([] === $lines) {
            throw new InvalidArgumentException('Un breakdown vide n\'explique rien : une transaction porte au moins une ligne.');
        }

        $this->lines = array_values($lines);
    }

    public function total(): int
    {
        return array_sum(array_map(static fn (XpBreakdownLine $line): int => $line->amount, $this->lines));
    }

    /**
     * Le détail d'une annulation : les mêmes lignes, inversées. Le joueur ne perd pas
     * « 121 XP » sans raison, il se voit reprendre exactement ce qui lui avait été donné,
     * ligne par ligne.
     */
    public function negated(): self
    {
        return new self(...array_map(static fn (XpBreakdownLine $line): XpBreakdownLine => $line->negated(), $this->lines));
    }
}
