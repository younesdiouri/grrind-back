<?php

declare(strict_types=1);

namespace App\Rewards\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Équiper un objet possédé (#29). Point d'entrée applicatif que la future route (#30)
 * appellera ; aucune route n'existe encore dans ce ticket.
 *
 * `$slot` est une chaîne brute, jamais un {@see \App\Rewards\Domain\EquipmentSlot} déjà
 * résolu — même choix que {@see \App\Combat\Application\FightBattle::$enemyKey} : la
 * validation d'un emplacement inconnu est une règle de domaine, pas un détail du transport
 * (#30), voir le docblock d'{@see \App\Rewards\Domain\Exception\EquipmentSlotUnknown}.
 */
final readonly class EquipItem
{
    public function __construct(
        public Uuid $userId,
        public string $itemKey,
        public string $slot,
    ) {
    }
}
