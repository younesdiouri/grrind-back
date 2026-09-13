<?php

declare(strict_types=1);

namespace App\Combat\Application;

use App\Combat\Domain\CombatRules;
use App\Combat\Domain\Enemy;
use App\Combat\Domain\Fighter;
use App\Combat\Domain\FighterDerivation;
use App\Shared\Application\GameRulesets;
use App\Shared\Application\ModifierResolver;
use App\Shared\Application\PlayerProgression;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/** Attributs bonifiés avant dérivation ; effets directs ajoutés ensuite et plafonnés en dernier.
 * Les paires utilisent floor(sqrt(a*b)), les taux un rendement décroissant.
 * Le resolver commun somme les équipements ; aucune stat brute ne rejoint le simulateur. */
final readonly class FighterFactory
{
    /** @param array<string, array{source: string, combination: string, secondary: ?string}> $formulas */
    public function __construct(
        private CombatRules|GameRulesets $rules,
        private ModifierResolver $modifiers,
        private array $formulas = [],
    ) {
    }

    /**
     * @param Uuid              $playerId   pour interroger {@see ModifierResolver} — `PlayerProgression` ne le porte pas
     * @param DateTimeImmutable $occurredAt l'instant du combat, jamais celui d'un sport — voir le docblock de la classe
     */
    public function forPlayer(PlayerProgression $progression, Uuid $playerId, DateTimeImmutable $occurredAt): Fighter
    {
        return $this->resolvePlayer($progression, $playerId, $occurredAt)['fighter'];
    }

    /**
     * Une seule résolution alimente le combat et son explication : les bonus affichés ne
     * doivent jamais être réappliqués côté client, notamment après saturation ou plancher.
     *
     * @return array{attributes: array<string, array{base: int, equipmentBonus: int, effective: int}>, fighter: Fighter}
     */
    public function resolvePlayer(PlayerProgression $progression, Uuid $playerId, DateTimeImmutable $occurredAt): array
    {
        $formulas = $this->formulas;
        if ($this->rules instanceof GameRulesets) {
            /** @var array{combat: array{formulas: array<string, array{source: string, combination: string, secondary: ?string}>}} $snapshot */
            $snapshot = $this->rules->snapshot();
            $formulas = $snapshot['combat']['formulas'];
        }

        return FighterDerivation::resolve($progression, $this->modifiers->of($playerId, $occurredAt), $this->rules(), $formulas);
    }

    public function forEnemy(Enemy $enemy): Fighter
    {
        return new Fighter($enemy->hp, $enemy->damage, $enemy->mitigationPermille, $enemy->comboPermille, $enemy->dodgePermille, $enemy->maintenancePermille, $enemy->criticalChancePermille, $enemy->guardPermille, $enemy->criticalResistancePermille, $enemy->cooldownReductionPermille, $enemy->precisionPermille);
    }

    private function rules(): CombatRules
    {
        if ($this->rules instanceof CombatRules) {
            return $this->rules;
        }

        $snapshot = $this->rules->snapshot();
        /** @var array{combat: array{fighter: array<string, int>}} $snapshot */
        /** @var array<string, int> $fighter */
        $fighter = $snapshot['combat']['fighter'];

        return CombatRules::fromSnapshot($fighter);
    }
}
