<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Domain\GameDesignProfile;
use App\Tests\Combat\CombatRulesFixture;
use App\Tests\Combat\StatFormulaFixture;

/** @phpstan-import-type ProfileInput from GameDesignProfile */
final class GameDesignFixture
{
    /** @return array<string, mixed> */
    public static function snapshot(): array
    {
        return [
            'combat' => ['fighter' => CombatRulesFixture::snapshot(), 'formulas' => StatFormulaFixture::data(), 'enemies' => [['key' => 'test', 'level' => 1, 'hp' => 500, 'damage' => 30, 'mitigation_permille' => 0, 'combo_permille' => 0, 'dodge_permille' => 0]], 'bosses' => []],
            'items' => [],
            'alam' => \App\Tests\Community\Domain\AlamOutcomeTest::rules()->values,
            'training' => ['minimum_duration_seconds' => 60, 'maximum_duration_seconds' => 3600, 'import_window_days' => 7],
            'xp' => ['base_xp_per_hour' => 60],
            'disciplines' => array_map(static fn (\App\Shared\Domain\Activity\Discipline $discipline): array => ['discipline' => $discipline->value, 'active' => true, 'credits_xp' => true, 'daily_cap_xp' => 70, 'xp_per_km' => 1, 'xp_per_100m_elevation' => 1, 'split' => ['strength' => 25, 'endurance' => 25, 'mobility' => 25, 'dexterity' => 25]], \App\Shared\Domain\Activity\Discipline::cases()),
            'levels' => [['level' => 1, 'total_xp' => 0], ['level' => 2, 'total_xp' => 100]],
            'attributes' => ['vitality' => ['floor_permille' => 250, 'window_days' => 7, 'target_active_kcal' => 500, 'bonus_cap_permille' => 500]],
        ];
    }

    /** @return ProfileInput */
    public static function profile(): array
    {
        return ['name' => 'Profil test', 'strength' => 1000, 'endurance' => 2000, 'mobility' => 3000, 'dexterity' => 4000, 'vitalityOverride' => null, 'equipment' => []];
    }

    /** @return array{hp: int, damage: int, mitigationPermille: int, comboPermille: int, dodgePermille: int, maintenancePermille: int, criticalChancePermille: int, guardPermille: int, criticalResistancePermille: int, cooldownReductionPermille: int, precisionPermille: int} */
    public static function enemy(): array
    {
        return ['hp' => 500, 'damage' => 30, 'mitigationPermille' => 0, 'comboPermille' => 0, 'dodgePermille' => 0, 'maintenancePermille' => 0, 'criticalChancePermille' => 0, 'guardPermille' => 0, 'criticalResistancePermille' => 0, 'cooldownReductionPermille' => 0, 'precisionPermille' => 0];
    }
}
