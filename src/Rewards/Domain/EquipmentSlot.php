<?php

declare(strict_types=1);

namespace App\Rewards\Domain;

/**
 * L'emplacement d'équipement d'un objet. Vocabulaire fermé, à `Rewards` : c'est
 * l'inventaire (#29) qui appliquera la règle « un objet par emplacement », pas ce ticket —
 * il ne fait que poser le champ dont cette règle aura besoin.
 */
enum EquipmentSlot: string
{
    case Head = 'HEAD';
    case Chest = 'CHEST';
    case Hands = 'HANDS';
    case Legs = 'LEGS';
    case Feet = 'FEET';
    case Accessory = 'ACCESSORY';
    case Weapon = 'WEAPON';
}
