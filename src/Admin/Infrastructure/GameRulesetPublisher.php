<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure;

use App\Admin\Domain\GameEnemy;
use App\Admin\Domain\GameItem;
use App\Admin\Domain\GameLootTable;
use App\Admin\Domain\GameRuleset;
use App\Admin\Domain\GameSettings;
use App\Admin\Domain\GameTitle;
use App\Combat\Domain\CombatRules;
use App\Combat\Domain\EnemyCatalog;
use App\Progression\Domain\TitleCatalog;
use App\Rewards\Domain\ItemCatalog;
use App\Rewards\Domain\LootLuckRules;
use App\Rewards\Domain\LootTables;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Le seul point de publication : il reconstruit les objets métier historiques dans la
 * transaction qui modifie EasyAdmin. Ainsi les validateurs restent sources uniques et
 * aucune ligne administrable à moitié cohérente ne fuit au runtime.
 */
final readonly class GameRulesetPublisher
{
    public function __construct(private TagAwareCacheInterface $cache)
    {
    }

    public function publish(EntityManagerInterface $manager): void
    {
        $ruleset = $manager->find(GameRuleset::class, 1, LockMode::PESSIMISTIC_WRITE);
        if (!$ruleset instanceof GameRuleset) {
            throw new LogicException('Le snapshot de jeu initial est absent. Rejouer les migrations avant d’ouvrir EasyAdmin.');
        }

        /** @var list<GameItem> $items */ $items = $manager->getRepository(GameItem::class)->findBy(['active' => true], ['sortOrder' => 'ASC']);
        /** @var list<GameTitle> $titles */ $titles = $manager->getRepository(GameTitle::class)->findBy(['active' => true], ['sortOrder' => 'ASC']);
        /** @var list<GameEnemy> $enemies */ $enemies = $manager->getRepository(GameEnemy::class)->findBy(['active' => true], ['sortOrder' => 'ASC']);
        /** @var list<GameLootTable> $tables */ $tables = $manager->getRepository(GameLootTable::class)->findBy(['active' => true], ['sortOrder' => 'ASC']);
        $settings = $manager->find(GameSettings::class, 1);
        if (!$settings instanceof GameSettings) {
            throw new LogicException('Les réglages globaux initiaux sont absents. Rejouer les migrations avant d’ouvrir EasyAdmin.');
        }

        $snapshot = self::snapshot($items, $titles, $enemies, $tables, $settings);
        self::validate($snapshot);

        $canonical = json_encode(self::canonicalize($snapshot), \JSON_THROW_ON_ERROR);
        $ruleset->publish($snapshot, 'v1-'.substr(hash('sha256', $canonical), 0, 12));
        $manager->flush();
        $this->cache->invalidateTags(['game.ruleset']);
    }

    /**
     * @param list<GameItem>      $items
     * @param list<GameTitle>     $titles
     * @param list<GameEnemy>     $enemies
     * @param list<GameLootTable> $tables
     *
     * @return array{items: list<array<string, mixed>>, titles: list<array<string, mixed>>, combat: array<string, mixed>, loot: array<string, mixed>}
     */
    private static function snapshot(array $items, array $titles, array $enemies, array $tables, GameSettings $settings): array
    {
        $itemRows = array_map(static fn (GameItem $item): array => [
            'key' => $item->getKey(), 'rarity' => $item->getRarity(), 'kind' => $item->getKind(), 'slot' => $item->getSlot(),
            'price_coins' => $item->getPriceCoins(), 'modifiers' => $item->getModifiers(),
            'shop' => ['available' => $item->isShopAvailable(), 'minimum_level' => $item->getShopMinimumLevel()],
            'image_path' => $item->getImagePath(), 'translations' => $item->getTranslations(),
        ], $items);
        $titleRows = array_map(static fn (GameTitle $title): array => [
            'id' => $title->getKey(), 'condition' => ['type' => $title->getConditionType(), 'threshold' => $title->getThreshold(), 'discipline' => $title->getDiscipline()], 'translations' => $title->getTranslations(),
        ], $titles);
        $enemyRows = ['enemies' => [], 'bosses' => []];
        foreach ($enemies as $enemy) {
            $row = ['key' => $enemy->getKey(), 'hp' => $enemy->getHp(), 'damage' => $enemy->getDamage(), 'mitigation_permille' => $enemy->getMitigationPermille(), 'extra_turn_permille' => $enemy->getExtraTurnPermille(), 'dodge_permille' => $enemy->getDodgePermille(), 'translations' => $enemy->getTranslations()];
            if ($enemy->isBoss()) {
                $row['minimum_level'] = $enemy->getMinimumLevel();
                $enemyRows['bosses'][] = $row;
            } else {
                $row['level'] = $enemy->getMinimumLevel();
                $enemyRows['enemies'][] = $row;
            }
        }
        $lootRows = ['workout' => [], 'adversary' => [], 'chest' => []];
        foreach ($tables as $table) {
            $row = ['key' => $table->getKey(), 'coins' => ['minimum' => $table->getCoinsMinimum(), 'maximum' => $table->getCoinsMaximum()], 'entries' => $table->getEntries()];
            if ('workout' === $table->getKind()) {
                $row['eligibility'] = $table->getEligibility();
            }
            $lootRows[$table->getKind()][] = $row;
        }

        return ['items' => $itemRows, 'titles' => $titleRows, 'combat' => ['fighter' => $settings->getFighter(), ...$enemyRows], 'loot' => ['loot_luck' => $settings->getLootLuck(), ...$lootRows]];
    }

    /** @param array{items: list<array<string, mixed>>, titles: list<array<string, mixed>>, combat: array<string, mixed>, loot: array<string, mixed>} $snapshot */
    private static function validate(array $snapshot): void
    {
        /** @var list<array{key: string, rarity: string, slot?: string, kind?: string, price_coins: int, modifiers: list<array{type: string, value: int, discipline?: string}>, shop?: array{available?: bool, minimum_level?: int}}> $items */ $items = $snapshot['items'];
        /** @var list<array{id: string, condition: array{type: string, threshold: int, discipline?: string|null}}> $titles */ $titles = $snapshot['titles'];
        /** @var array{base_hp: int, hp_per_1000_vitality: int, base_damage: int, damage_per_1000_strength: int, mitigation_permille_per_1000_endurance: int, mitigation_cap_permille: int, extra_turn_permille_per_1000_dexterity: int, extra_turn_cap_permille: int, dodge_permille_per_1000_mobility: int, dodge_cap_permille: int, minimum_damage: int, max_turns: int} $fighter */ $fighter = $snapshot['combat']['fighter'];
        /** @var list<array{key: string, level: int, hp: int, damage: int, mitigation_permille: int, extra_turn_permille: int, dodge_permille: int}> $enemies */ $enemies = $snapshot['combat']['enemies'];
        /** @var list<array{key: string, minimum_level: int, hp: int, damage: int, mitigation_permille: int, extra_turn_permille: int, dodge_permille: int}> $bosses */ $bosses = $snapshot['combat']['bosses'];
        /** @var array{floor_percent: int, cap_percent: int} $lootLuck */ $lootLuck = $snapshot['loot']['loot_luck'];
        /** @var list<array{key: string, eligibility: array{disciplines: list<string>, minimum_duration_minutes: int, minimum_level: int}, coins: array{minimum: int, maximum: int}, entries: list<array{item?: string, weight: int}>}> $workout */ $workout = $snapshot['loot']['workout'];
        /** @var list<array{key: string, coins: array{minimum: int, maximum: int}, entries: list<array{item?: string, weight: int}>}> $adversary */ $adversary = $snapshot['loot']['adversary'];
        /** @var list<array{key: string, coins: array{minimum: int, maximum: int}, entries: list<array{item?: string, weight: int}>}> $chest */ $chest = $snapshot['loot']['chest'];
        new ItemCatalog($items);
        new TitleCatalog($titles);
        new CombatRules($fighter['base_hp'], $fighter['hp_per_1000_vitality'], $fighter['base_damage'], $fighter['damage_per_1000_strength'], $fighter['mitigation_permille_per_1000_endurance'], $fighter['mitigation_cap_permille'], $fighter['extra_turn_permille_per_1000_dexterity'], $fighter['extra_turn_cap_permille'], $fighter['dodge_permille_per_1000_mobility'], $fighter['dodge_cap_permille'], $fighter['minimum_damage'], $fighter['max_turns']);
        new EnemyCatalog($enemies, $bosses);
        new LootLuckRules($lootLuck['floor_percent'], $lootLuck['cap_percent']);
        new LootTables(1, $workout, $adversary, $chest, $items, $enemies, $bosses);
    }

    /**
     * @param array<int|string, mixed> $values
     *
     * @return array<int|string, mixed>
     */
    private static function canonicalize(array $values): array
    {
        if (!array_is_list($values)) {
            ksort($values);
        }
        foreach ($values as $key => $value) {
            if (\is_array($value)) {
                $values[$key] = self::canonicalize($value);
            }
        }

        return $values;
    }
}
