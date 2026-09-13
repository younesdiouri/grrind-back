<?php

declare(strict_types=1);

namespace App\Combat\Domain;

use LogicException;

/** Le contrat de restitution reste identique pour les batailles réelles et les essais sans effets. */
final class CombatSnapshot
{
    /**
     * @return array{hp: int, damage: int, mitigationPermille: int, comboPermille: int, dodgePermille: int, maintenancePermille: int, criticalChancePermille: int, guardPermille: int, criticalResistancePermille: int, cooldownReductionPermille: int, precisionPermille: int}
     */
    public static function fighter(Fighter $fighter): array
    {
        return [
            'hp' => $fighter->hp,
            'damage' => $fighter->damage,
            'mitigationPermille' => $fighter->mitigationPermille,
            'comboPermille' => $fighter->comboPermille,
            'dodgePermille' => $fighter->dodgePermille,
            'maintenancePermille' => $fighter->maintenancePermille,
            'criticalChancePermille' => $fighter->criticalChancePermille,
            'guardPermille' => $fighter->guardPermille,
            'criticalResistancePermille' => $fighter->criticalResistancePermille,
            'cooldownReductionPermille' => $fighter->cooldownReductionPermille,
            'precisionPermille' => $fighter->precisionPermille,
        ];
    }

    /**
     * Le mapping écrit à la main qui fige la forme d'un événement — voir le docblock de la
     * classe pour pourquoi ni un cast ni un `json_encode` implicite ne conviennent.
     *
     * @return array<string, mixed>
     */
    public static function event(BattleEvent $event): array
    {
        return match (true) {
            $event instanceof BattleStarted => [
                'type' => 'BATTLE_STARTED',
                'playerHp' => $event->playerHp,
                'enemyHp' => $event->enemyHp,
            ],
            $event instanceof Attack => [
                'type' => 'ATTACK',
                'attacker' => $event->attacker->value,
                'damage' => $event->damage,
                'mitigated' => $event->mitigated,
                'targetHpRemaining' => $event->targetHpRemaining,
                'critical' => $event->critical,
                'guarded' => $event->guarded,
                'powerPermille' => $event->powerPermille,
                'baseDamage' => $event->baseDamage,
                'fatiguedDamage' => $event->fatiguedDamage,
                'criticalDamage' => $event->criticalDamage,
                'guardReduction' => $event->guardReduction,
                'minimumDamageAdded' => $event->minimumDamageAdded,
                'atTick' => $event->atTick,
                'actionIndex' => $event->actionIndex,
                'attackIndex' => $event->attackIndex,
            ],
            $event instanceof Dodge => [
                'type' => 'DODGE',
                'attacker' => $event->attacker->value,
                'powerPermille' => $event->powerPermille,
                'atTick' => $event->atTick,
                'actionIndex' => $event->actionIndex,
                'attackIndex' => $event->attackIndex,
            ],
            $event instanceof Combo => [
                'type' => 'COMBO',
                'actor' => $event->actor->value,
                'atTick' => $event->atTick,
                'actionIndex' => $event->actionIndex,
                'attackIndex' => $event->attackIndex,
            ],
            $event instanceof BattleFinished => [
                'type' => 'BATTLE_FINISHED',
                'result' => $event->result->value,
                'endReason' => $event->endReason->value,
                'atTick' => $event->atTick,
                'actionCount' => $event->actionCount,
                'attackCount' => $event->attackCount,
            ],
            // Fermé aux cinq formes de BattleEvent (#218 en ajoute une) : une sixième qui
            // arriverait sans mapping ici doit casser tout de suite, pas s'écrire
            // silencieusement en JSONB amputée de ses champs.
            default => throw new LogicException(\sprintf('Aucune forme de sérialisation pour un événement de combat "%s".', $event::class)),
        };
    }
}
