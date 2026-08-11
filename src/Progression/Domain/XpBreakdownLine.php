<?php

declare(strict_types=1);

namespace App\Progression\Domain;

/**
 * Une contribution au total d'une transaction : d'où elle vient, ce qu'elle pèse.
 *
 * Entier signé, jamais un flottant : un multiplicateur ×1,2 se résout en un nombre de
 * points *avant* d'arriver ici. Ce qui est écrit au ledger est ce que le joueur a reçu,
 * pas la formule qui l'a produit — c'est ce qui rend la somme exacte et l'historique
 * reconstructible sans rejouer le calcul.
 *
 * Aucune contrainte de signe par source, volontairement : elle ne tiendrait pas à
 * l'annulation, où toutes les lignes s'inversent — un `DIMINISHING` négatif au crédit
 * redevient positif quand on rend au joueur ce qu'on lui avait rogné. La discipline des
 * signes appartient au calcul (#14), qui la démontre par sa table de cas.
 */
final readonly class XpBreakdownLine
{
    public function __construct(
        public XpBreakdownSource $source,
        public int $amount,
    ) {
    }

    public function negated(): self
    {
        return new self($this->source, -$this->amount);
    }
}
