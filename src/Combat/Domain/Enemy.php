<?php

declare(strict_types=1);

namespace App\Combat\Domain;

/** Effets résolus, sans attributs bruts. Les bornes du catalogue sont vérifiées à publication. */
final readonly class Enemy
{
    public function __construct(
        public string $key,
        public int $level,
        public int $hp,
        public int $damage,
        public int $mitigationPermille,
        public int $comboPermille,
        public int $dodgePermille,
        public int $maintenancePermille = 0,
        public int $criticalChancePermille = 0,
        public int $guardPermille = 0,
        public int $criticalResistancePermille = 0,
        public int $cooldownReductionPermille = 0,
        public int $precisionPermille = 0,
    ) {
    }
}
