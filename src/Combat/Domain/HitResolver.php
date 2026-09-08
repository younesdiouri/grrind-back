<?php

declare(strict_types=1);

namespace App\Combat\Domain;

use Random\Randomizer;

/** Un seul chemin pour les coups réguliers et bonus. Chaque multiplication est tronquée,
 * puis le minimum s'applique une seule fois à la fin. Esquive et critique sont relatifs. */
final readonly class HitResolver
{
    public function __construct(private CombatRules $rules)
    {
    }

    public function resolve(Fighter $attacker, Fighter $target, Actor $actor, int $targetHp, int $attempts, int $tick, int $actionIndex, int $attackIndex, Randomizer $rng): Attack|Dodge
    {
        $power = $this->rules->fatigueFloorPermille + intdiv((1000 - $this->rules->fatigueFloorPermille) * $this->rules->fatigueCapacity, $this->rules->fatigueCapacity + $attempts * (1000 - $attacker->maintenancePermille));
        $dodge = CombatMath::scale($target->dodgePermille, 1000 - $attacker->precisionPermille);
        if ($rng->getInt(0, 999) < $dodge) {
            return new Dodge($actor, $power, $tick, $actionIndex, $attackIndex);
        }
        $critical = $rng->getInt(0, 999) < CombatMath::scale($attacker->criticalChancePermille, 1000 - $target->criticalResistancePermille);
        $guarded = $rng->getInt(0, 999) < $target->guardPermille;
        $fatigued = CombatMath::scale($attacker->damage, $power);
        $amplified = $critical ? CombatMath::scale($fatigued, $this->rules->criticalMultiplierPermille) : $fatigued;
        $mitigated = CombatMath::scale($amplified, 1000 - $target->mitigationPermille);
        $guardedDamage = $guarded ? intdiv($mitigated, 2) : $mitigated;
        $damage = max($this->rules->minimumDamage, $guardedDamage);

        return new Attack($actor, $damage, $amplified - $mitigated, max(0, $targetHp - $damage), $critical, $guarded, $power, $attacker->damage, $fatigued, $amplified, $mitigated - $guardedDamage, $damage - $guardedDamage, $tick, $actionIndex, $attackIndex);
    }
}
