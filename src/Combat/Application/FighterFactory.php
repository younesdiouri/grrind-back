<?php

declare(strict_types=1);

namespace App\Combat\Application;

use App\Combat\Domain\CombatMath;
use App\Combat\Domain\CombatRules;
use App\Combat\Domain\Enemy;
use App\Combat\Domain\Fighter;
use App\Shared\Application\GameRulesets;
use App\Shared\Application\ModifierResolver;
use App\Shared\Application\PlayerProgression;
use App\Shared\Domain\Modifier\Modifier;
use App\Shared\Domain\Modifier\ModifierSource;
use App\Shared\Domain\Modifier\ModifierType;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/** Attributs bonifiés avant dérivation ; effets directs ajoutés ensuite et plafonnés en dernier.
 * Les paires utilisent floor(sqrt(a*b)), les taux un rendement décroissant.
 * Le resolver commun somme les équipements ; aucune stat brute ne rejoint le simulateur. */
final readonly class FighterFactory
{
    public function __construct(
        private CombatRules|GameRulesets $rules,
        private ModifierResolver $modifiers,
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
        $rules = $this->rules();
        $modifiers = $this->modifiers->of($playerId, $occurredAt);
        $attributes = [];
        $baseAttributes = $progression->attributes->toArray();
        $equipmentModifiers = array_values(array_filter($modifiers, static fn (Modifier $modifier): bool => ModifierSource::Item === $modifier->source));
        foreach ([
            'strength' => ModifierType::StrengthBonus,
            'endurance' => ModifierType::EnduranceBonus,
            'mobility' => ModifierType::MobilityBonus,
            'dexterity' => ModifierType::DexterityBonus,
        ] as $name => $type) {
            $base = $baseAttributes[$name];
            $attributes[$name] = [
                'base' => $base,
                'equipmentBonus' => self::sumOf($equipmentModifiers, $type),
                'effective' => max(0, CombatMath::add($base, self::sumOf($modifiers, $type))),
            ];
        }
        // La vitalité inclut déjà le bonus d'énergie active ; aucun objet ne la bonifie.
        $vitality = max(0, $progression->vitality);
        $attributes['vitality'] = ['base' => $progression->vitality, 'equipmentBonus' => 0, 'effective' => $vitality];
        $strength = $attributes['strength']['effective'];
        $endurance = $attributes['endurance']['effective'];
        $mobility = $attributes['mobility']['effective'];
        $dexterity = $attributes['dexterity']['effective'];

        $fighter = new Fighter(
            hp: max(1, CombatMath::add(CombatMath::add($rules->baseHp, CombatMath::scale($vitality, $rules->hpPer1000Vitality)), self::sumOf($modifiers, ModifierType::HpBonus))),
            damage: max(0, CombatMath::add(CombatMath::add($rules->baseDamage, CombatMath::scale($strength, $rules->damagePer1000Strength)), self::sumOf($modifiers, ModifierType::DamageBonus))),
            maintenancePermille: min($rules->maintenanceCapPermille, max(0, CombatMath::add(CombatMath::rate($endurance, $rules->maintenanceCapPermille, $rules->maintenanceHalfSaturation), self::sumOf($modifiers, ModifierType::MaintenanceBonus)))),
            dodgePermille: min($rules->dodgeCapPermille, max(0, CombatMath::add(CombatMath::rate($mobility, $rules->dodgeCapPermille, $rules->dodgeHalfSaturation), self::sumOf($modifiers, ModifierType::DodgeBonus)))),
            criticalChancePermille: min($rules->criticalChanceCapPermille, max(0, CombatMath::add(CombatMath::rate($dexterity, $rules->criticalChanceCapPermille, $rules->criticalChanceHalfSaturation), self::sumOf($modifiers, ModifierType::CriticalChanceBonus)))),
            mitigationPermille: min($rules->mitigationCapPermille, max(0, CombatMath::add(CombatMath::rate(CombatMath::pair($strength, $endurance), $rules->mitigationCapPermille, $rules->mitigationHalfSaturation), self::sumOf($modifiers, ModifierType::MitigationBonus)))),
            guardPermille: min($rules->guardCapPermille, max(0, CombatMath::add(CombatMath::rate(CombatMath::pair($strength, $mobility), $rules->guardCapPermille, $rules->guardHalfSaturation), self::sumOf($modifiers, ModifierType::GuardBonus)))),
            criticalResistancePermille: min($rules->criticalResistanceCapPermille, max(0, CombatMath::add(CombatMath::rate(CombatMath::pair($strength, $dexterity), $rules->criticalResistanceCapPermille, $rules->criticalResistanceHalfSaturation), self::sumOf($modifiers, ModifierType::CriticalResistanceBonus)))),
            cooldownReductionPermille: min($rules->cooldownReductionCapPermille, max(0, CombatMath::add(CombatMath::rate(CombatMath::pair($endurance, $mobility), $rules->cooldownReductionCapPermille, $rules->cooldownReductionHalfSaturation), self::sumOf($modifiers, ModifierType::CooldownReductionBonus)))),
            comboPermille: min($rules->comboCapPermille, max(0, CombatMath::add(CombatMath::rate(CombatMath::pair($endurance, $dexterity), $rules->comboCapPermille, $rules->comboHalfSaturation), self::sumOf($modifiers, ModifierType::ComboBonus)))),
            precisionPermille: min($rules->precisionCapPermille, max(0, CombatMath::add(CombatMath::rate(CombatMath::pair($mobility, $dexterity), $rules->precisionCapPermille, $rules->precisionHalfSaturation), self::sumOf($modifiers, ModifierType::PrecisionBonus)))),
        );

        return ['attributes' => $attributes, 'fighter' => $fighter];
    }

    public function forEnemy(Enemy $enemy): Fighter
    {
        return new Fighter($enemy->hp, $enemy->damage, $enemy->mitigationPermille, $enemy->comboPermille, $enemy->dodgePermille, $enemy->maintenancePermille, $enemy->criticalChancePermille, $enemy->guardPermille, $enemy->criticalResistancePermille, $enemy->cooldownReductionPermille, $enemy->precisionPermille);
    }

    /**
     * La composition retenue pour plusieurs modificateurs du même type : la somme — voir le
     * docblock de la classe pour pourquoi, et pourquoi {@see Modifier::$discipline} n'entre
     * pas en ligne de compte ici.
     *
     * @param list<Modifier> $modifiers
     */
    private static function sumOf(array $modifiers, ModifierType $type): int
    {
        $total = 0;

        foreach ($modifiers as $modifier) {
            if ($modifier->type === $type) {
                $total = CombatMath::add($total, $modifier->value);
            }
        }

        return $total;
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
