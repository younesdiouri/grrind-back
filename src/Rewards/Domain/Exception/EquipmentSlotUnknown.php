<?php

declare(strict_types=1);

namespace App\Rewards\Domain\Exception;

use App\Shared\Domain\Exception\RuleViolationError;

/**
 * La chaîne reçue pour un emplacement ne correspond à aucun cas d'{@see \App\Rewards\Domain\EquipmentSlot}
 * (#29). Validée ici plutôt qu'à la désérialisation de la future requête HTTP (#30) : la
 * commande d'équipement reçoit l'emplacement en chaîne brute, exactement comme
 * {@see \App\Combat\Application\FightBattle} reçoit une clé d'ennemi brute, pour que le refus
 * soit une règle de domaine et non un détail du transport.
 */
final class EquipmentSlotUnknown extends RuleViolationError
{
    public function __construct(string $slot)
    {
        parent::__construct(
            \sprintf('"%s" ne désigne aucun emplacement d\'équipement connu.', $slot),
            ['slot' => $slot],
        );
    }

    public function type(): string
    {
        return 'equipment-slot-unknown';
    }
}
