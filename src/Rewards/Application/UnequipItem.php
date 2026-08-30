<?php

declare(strict_types=1);

namespace App\Rewards\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Vider un emplacement d'équipement (#29) — on désigne l'emplacement, pas l'objet qui s'y
 * trouve : c'est le geste naturel de « retirer ce que je porte à la tête », et il reste valide
 * même si le joueur ne se souvient plus lequel de ses objets y est. Voir {@see EquipItem} pour
 * pourquoi `$slot` est une chaîne brute.
 */
final readonly class UnequipItem
{
    public function __construct(
        public Uuid $userId,
        public string $slot,
    ) {
    }
}
