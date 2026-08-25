<?php

declare(strict_types=1);

namespace App\Shared\Domain\Activity;

/**
 * Ce qu'un montant d'XP a rapporté, réparti sur les quatre caractéristiques — le rendu de
 * {@see AttributeSplit::distribute()}.
 *
 * Les quatre entiers peuvent être négatifs : une séance invalidée produit une transaction
 * d'XP négative, donc un `AttributeGains` négatif qui la solde exactement. `total()` doit
 * toujours valoir le montant réparti au départ, jamais une approximation — c'est
 * l'invariant que porte `AttributeSplit`, pas celui-ci : ce type ne fait que transporter le
 * résultat.
 */
final readonly class AttributeGains
{
    public function __construct(
        public int $strength,
        public int $endurance,
        public int $mobility,
        public int $dexterity,
    ) {
    }

    public function total(): int
    {
        return $this->strength + $this->endurance + $this->mobility + $this->dexterity;
    }
}
