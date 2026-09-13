<?php

declare(strict_types=1);

namespace App\Combat\Domain;

enum DerivedStat: string
{
    case Hp = 'hp';
    case Damage = 'damage';
    case Maintenance = 'maintenance';
    case Dodge = 'dodge';
    case CriticalChance = 'critical_chance';
    case Mitigation = 'mitigation';
    case Guard = 'guard';
    case CriticalResistance = 'critical_resistance';
    case CooldownReduction = 'cooldown_reduction';
    case Combo = 'combo';
    case Precision = 'precision';

    public function label(): string
    {
        return match ($this) {
            self::Hp => 'Points de vie', self::Damage => 'Dégâts', self::Maintenance => 'Maintien de puissance',
            self::Dodge => 'Esquive', self::CriticalChance => 'Chance de critique', self::Mitigation => 'Réduction des dégâts',
            self::Guard => 'Garde', self::CriticalResistance => 'Résistance critique', self::CooldownReduction => 'Réduction du délai',
            self::Combo => 'Combo', self::Precision => 'Précision',
        };
    }
}
