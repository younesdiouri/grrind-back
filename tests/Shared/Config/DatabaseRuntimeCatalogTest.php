<?php

declare(strict_types=1);

namespace App\Tests\Shared\Config;

use App\Combat\Domain\EnemyCatalog;
use App\Rewards\Domain\ItemCatalog;
use App\Shared\Application\GameRulesets;
use App\Shared\Infrastructure\Config\GameRulesetVersion;
use PHPUnit\Framework\TestCase;

/** Les entrées retirées restent lisibles pour les faits, jamais sélectionnables à nouveau. */
final class DatabaseRuntimeCatalogTest extends TestCase
{
    public function testInactiveItemRemainsHistoricallyResolvableButCannotBeBought(): void
    {
        $catalog = ItemCatalog::runtime($this->rulesets());

        self::assertNotNull($catalog->findHistorical('OLD_BOOTS'));
        self::assertNull($catalog->findAvailable('OLD_BOOTS'));
    }

    public function testInactiveEnemyCannotBeChosenButActiveLevelSelectionRemainsAvailable(): void
    {
        $catalog = EnemyCatalog::runtime($this->rulesets());

        self::assertNotNull($catalog->findHistorical('OLD_ENEMY'));
        self::assertNull($catalog->findAvailable('OLD_ENEMY'));
        self::assertSame('ACTIVE_ENEMY', $catalog->forLevel(1)->key);
    }

    public function testHybridVersionChangesForGameplayButNotPresentation(): void
    {
        $snapshot = $this->rulesets()->snapshot();
        /** @var array{items: list<array<string, mixed>>} $snapshot */
        $version = GameRulesetVersion::of('v1-yaml', $snapshot);
        $presentation = $snapshot;
        $presentation['items'][0]['image_path'] = 'new.png';
        $presentation['items'][0]['translations'] = ['fr' => ['name' => 'Nouveau']];
        self::assertSame($version, GameRulesetVersion::of('v1-yaml', $presentation));

        $gameplay = $snapshot;
        $gameplay['items'][0]['price_coins'] = 2;
        self::assertNotSame($version, GameRulesetVersion::of('v1-yaml', $gameplay));
        self::assertNotSame($version, GameRulesetVersion::of('v1-other-yaml', $snapshot));
    }

    private function rulesets(): GameRulesets
    {
        return new class implements GameRulesets {
            public function snapshot(): array
            {
                return [
                    'items' => [
                        ['key' => 'OLD_BOOTS', 'active' => false, 'rarity' => 'COMMON', 'kind' => 'EQUIPMENT', 'slot' => 'FEET', 'price_coins' => 1, 'modifiers' => [], 'shop' => ['available' => true, 'minimum_level' => 1]],
                        ['key' => 'ACTIVE_BOOTS', 'active' => true, 'rarity' => 'COMMON', 'kind' => 'EQUIPMENT', 'slot' => 'FEET', 'price_coins' => 1, 'modifiers' => [], 'shop' => ['available' => true, 'minimum_level' => 1]],
                    ],
                    'titles' => [],
                    'combat' => [
                        'fighter' => [],
                        'enemies' => [
                            ['key' => 'OLD_ENEMY', 'active' => false, 'level' => 2, 'hp' => 1, 'damage' => 0, 'mitigation_permille' => 0, 'extra_turn_permille' => 0, 'dodge_permille' => 0],
                            ['key' => 'ACTIVE_ENEMY', 'active' => true, 'level' => 1, 'hp' => 1, 'damage' => 0, 'mitigation_permille' => 0, 'extra_turn_permille' => 0, 'dodge_permille' => 0],
                        ],
                        'bosses' => [],
                    ],
                    'loot' => ['version' => 1, 'loot_luck' => ['floor_percent' => 0, 'cap_percent' => 100], 'workout' => [], 'adversary' => [], 'chest' => []],
                ];
            }

            public function version(): string
            {
                return 'v1-test';
            }

            public function revision(): int
            {
                return 1;
            }
        };
    }
}
