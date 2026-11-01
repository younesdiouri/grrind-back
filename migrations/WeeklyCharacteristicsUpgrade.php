<?php

declare(strict_types=1);

namespace DoctrineMigrations;

/** Conversion figée de développement (#332) ; les publications et résultats passés restent intacts. */
final class WeeklyCharacteristicsUpgrade
{
    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public static function snapshot(array $snapshot): array
    {
        /** @var list<array<string, mixed>> $items */
        $items = $snapshot['items'];
        foreach ($items as &$item) {
            /** @var list<array{type: string}> $modifiers */
            $modifiers = $item['modifiers'] ?? [];
            $item['modifiers'] = array_values(array_filter($modifiers, static fn (array $modifier): bool => !\in_array($modifier['type'], ['STRENGTH_BONUS', 'ENDURANCE_BONUS', 'MOBILITY_BONUS', 'DEXTERITY_BONUS'], true)));
        }
        unset($item);
        $snapshot['items'] = $items;
        /** @var array<string, mixed> $combat */
        $combat = $snapshot['combat'];
        /** @var array<string, mixed> $formulas */
        $formulas = $combat['formulas'];
        $combat['formulas'] = array_intersect_key($formulas, ['hp' => true, 'damage' => true]);
        $snapshot['combat'] = $combat;

        return $snapshot;
    }

    /** @param array<string, mixed> $snapshot */
    public static function version(array $snapshot): string
    {
        require_once __DIR__.'/AlamRulesetVersion.php';
        /** @var list<array<string, mixed>> $items */
        $items = $snapshot['items'];
        foreach ($items as &$item) {
            unset($item['weapon_type']);
        }
        unset($item);
        $snapshot['items'] = $items;

        return AlamRulesetVersion::of($snapshot);
    }
}
