<?php

declare(strict_types=1);

namespace App\Rewards\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Acheter un objet de l'étal (#229). `$itemKey` est une chaîne brute, jamais un
 * {@see \App\Rewards\Domain\Item} déjà résolu — même choix qu'{@see EquipItem} pour une clé
 * d'objet : la résolution et ses refus (`item-not-purchasable`, `shop-level-too-low`,
 * `item-already-owned`, `insufficient-coin-balance`) sont des règles de domaine, pas un
 * détail du transport.
 */
final readonly class PurchaseItem
{
    public function __construct(
        public Uuid $userId,
        public string $itemKey,
    ) {
    }
}
