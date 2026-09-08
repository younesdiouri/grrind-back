<?php

declare(strict_types=1);

namespace App\Combat\Domain;

use InvalidArgumentException;

/** Effets résolus, sans attributs bruts. Les bornes du catalogue sont vérifiées à publication. */
final readonly class Fighter
{
    public function __construct(
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
        if ($hp < 1 || $damage < 0) {
            throw new InvalidArgumentException('PV ou dégâts invalides.');
        }
        if ($maintenancePermille < 0 || $maintenancePermille > 1000) {
            throw new InvalidArgumentException('Taux maintenance invalide.');
        }
        if ($dodgePermille < 0 || $dodgePermille > 1000) {
            throw new InvalidArgumentException('Taux dodge invalide.');
        }
        if ($criticalChancePermille < 0 || $criticalChancePermille > 1000) {
            throw new InvalidArgumentException('Taux criticalChance invalide.');
        }
        if ($mitigationPermille < 0 || $mitigationPermille > 1000) {
            throw new InvalidArgumentException('Taux mitigation invalide.');
        }
        if ($guardPermille < 0 || $guardPermille > 1000) {
            throw new InvalidArgumentException('Taux guard invalide.');
        }
        if ($criticalResistancePermille < 0 || $criticalResistancePermille > 1000) {
            throw new InvalidArgumentException('Taux criticalResistance invalide.');
        }
        if ($cooldownReductionPermille < 0 || $cooldownReductionPermille > 1000) {
            throw new InvalidArgumentException('Taux cooldownReduction invalide.');
        }
        if ($comboPermille < 0 || $comboPermille > 1000) {
            throw new InvalidArgumentException('Taux combo invalide.');
        }
        if ($precisionPermille < 0 || $precisionPermille > 1000) {
            throw new InvalidArgumentException('Taux precision invalide.');
        }
    }
}
