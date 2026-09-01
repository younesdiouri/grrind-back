<?php

declare(strict_types=1);

namespace App\Rewards\Domain;

use InvalidArgumentException;

/**
 * Une bande de pièces, jamais un montant fixe — voir le docblock de le snapshot publié : un
 * nombre constant n'est pas une récompense, c'est un compteur. Le tirage réel (#28) piochera
 * dedans sous la même graine que les objets, pour rester auditable au même titre.
 */
final readonly class CoinBand
{
    public function __construct(
        public int $minimum,
        public int $maximum,
    ) {
        if ($this->minimum < 0) {
            throw new InvalidArgumentException(\sprintf('Une bande de pièces ne peut pas commencer sous zéro, %d demandé.', $this->minimum));
        }

        if ($this->maximum < $this->minimum) {
            throw new InvalidArgumentException(\sprintf('Bande de pièces inversée : le maximum (%d) est sous le minimum (%d).', $this->maximum, $this->minimum));
        }
    }
}
