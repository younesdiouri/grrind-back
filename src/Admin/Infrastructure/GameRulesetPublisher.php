<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure;

use App\Admin\Domain\GameEnemy;
use App\Admin\Domain\GameActivityType;
use App\Admin\Domain\GameDiscipline;
use App\Admin\Domain\GameItem;
use App\Admin\Domain\GameLevel;
use App\Admin\Domain\GameLootTable;
use App\Admin\Domain\GameRuleset;
use App\Admin\Domain\GameSettings;
use App\Admin\Domain\GameTitle;
use App\Combat\Domain\CombatRules;
use App\Combat\Domain\EnemyCatalog;
use App\Progression\Domain\TitleCatalog;
use App\Progression\Domain\DiminishingReturns;
use App\Progression\Domain\LevelCurve;
use App\Progression\Domain\XpRates;
use App\Rewards\Domain\ItemCatalog;
use App\Rewards\Domain\LootLuckRules;
use App\Rewards\Domain\LootTables;
use App\Shared\Infrastructure\Config\GameRulesetVersion;
use App\Shared\Domain\Activity\ActivityTypeMap;
use App\Shared\Domain\Activity\AttributeSplit;
use App\Shared\Domain\Activity\Vitality;
use App\Training\Domain\WorkoutRules;
use App\Community\Domain\GuildRules;
use App\Community\Domain\QuietHours;
use App\Community\Domain\RisalaRules;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Throwable;

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

        /** @var list<GameItem> $items */ $items = $manager->getRepository(GameItem::class)->findBy([], ['sortOrder' => 'ASC']);
        /** @var list<GameTitle> $titles */ $titles = $manager->getRepository(GameTitle::class)->findBy([], ['sortOrder' => 'ASC']);
        /** @var list<GameEnemy> $enemies */ $enemies = $manager->getRepository(GameEnemy::class)->findBy([], ['sortOrder' => 'ASC']);
        /** @var list<GameLootTable> $tables */ $tables = $manager->getRepository(GameLootTable::class)->findBy([], ['sortOrder' => 'ASC']);
        /** @var list<GameDiscipline> $disciplines */ $disciplines = $manager->getRepository(GameDiscipline::class)->findBy([], ['sortOrder' => 'ASC']);
        /** @var list<GameLevel> $levels */ $levels = $manager->getRepository(GameLevel::class)->findBy([], ['level' => 'ASC']);
        /** @var list<GameActivityType> $activityTypes */ $activityTypes = $manager->getRepository(GameActivityType::class)->findBy([]);
        $settings = $manager->find(GameSettings::class, 1);
        if (!$settings instanceof GameSettings) {
            throw new LogicException('Les réglages globaux initiaux sont absents. Rejouer les migrations avant d’ouvrir EasyAdmin.');
        }

        foreach ([...$items, ...$titles, ...$enemies, ...$tables] as $configuration) {
            if ($configuration->isActive()) {
                $configuration->markPublishedActive();
            }
        }

        $snapshot = self::snapshot($items, $titles, $enemies, $tables, $disciplines, $levels, $activityTypes, $settings);
        $previous = $ruleset->snapshot();
        if (self::lootGameplay($previous) !== self::lootGameplay($snapshot)) {
            $settings->incrementLootVersion();
            $snapshot['loot']['version'] = $settings->lootVersion();
        }
        self::validate($snapshot);

        $ruleset->publish($snapshot, GameRulesetVersion::of($snapshot));
        $manager->flush();
    }

    /** Le cache est une accélération : la publication DB réussie ne dépend jamais de lui. */
    public function invalidateAfterCommit(): void
    {
        try {
            $this->cache->invalidateTags(['game.ruleset']);
        } catch (Throwable) {
            // La prochaine lecture retombe sur PostgreSQL.
        }
    }

    /**
     * @param list<GameItem>      $items
     * @param list<GameTitle>     $titles
     * @param list<GameEnemy>     $enemies
     * @param list<GameLootTable> $tables
     *
     * @return array<string, mixed>
     */
    private static function snapshot(array $items, array $titles, array $enemies, array $tables, array $disciplines, array $levels, array $activityTypes, GameSettings $settings): array
    {
        $itemRows = array_map(static fn (GameItem $item): array => [
            'key' => $item->getKey(), 'active' => $item->isActive(), 'rarity' => $item->getRarity(), 'kind' => $item->getKind(), 'slot' => $item->getSlot(),
            'price_coins' => $item->getPriceCoins(), 'modifiers' => $item->getModifiers(),
            'shop' => ['available' => $item->isShopAvailable(), 'minimum_level' => $item->getShopMinimumLevel()],
            'image_path' => $item->getImagePath(), 'translations' => $item->getTranslations(),
        ], $items);
        $titleRows = array_map(static fn (GameTitle $title): array => [
            'id' => $title->getKey(), 'active' => $title->isActive(), 'condition' => ['type' => $title->getConditionType(), 'threshold' => $title->getThreshold(), 'discipline' => $title->getDiscipline()], 'translations' => $title->getTranslations(),
        ], $titles);
        $enemyRows = ['enemies' => [], 'bosses' => []];
        foreach ($enemies as $enemy) {
            $row = ['key' => $enemy->getKey(), 'active' => $enemy->isActive(), 'hp' => $enemy->getHp(), 'damage' => $enemy->getDamage(), 'mitigation_permille' => $enemy->getMitigationPermille(), 'extra_turn_permille' => $enemy->getExtraTurnPermille(), 'dodge_permille' => $enemy->getDodgePermille(), 'translations' => $enemy->getTranslations()];
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
            $row = ['key' => $table->getKey(), 'active' => $table->isActive(), 'coins' => ['minimum' => $table->getCoinsMinimum(), 'maximum' => $table->getCoinsMaximum()], 'entries' => $table->getEntries()];
            if ('workout' === $table->getKind()) {
                $row['eligibility'] = $table->getEligibility();
            }
            $lootRows[$table->getKind()][] = $row;
        }

        $disciplineRows = array_map(static fn (GameDiscipline $discipline): array => [
            'discipline' => $discipline->getDiscipline()->value, 'active' => $discipline->isActive(), 'sort_order' => $discipline->getSortOrder(), 'credits_xp' => $discipline->creditsXp(),
            'daily_cap_xp' => $discipline->getDailyCapXp(), 'xp_per_km' => $discipline->getXpPerKm(), 'xp_per_100m_elevation' => $discipline->getXpPer100mElevation(),
            'split' => $discipline->getSplit(), 'translations' => $discipline->getTranslations(),
        ], $disciplines);
        $levelRows = array_map(static fn (GameLevel $level): array => ['level' => $level->getLevel(), 'total_xp' => $level->getTotalXp(), 'skill_points' => $level->getSkillPoints()], $levels);
        $activityRows = array_map(static fn (GameActivityType $activityType): array => ['source' => $activityType->getSource()->value, 'provider_type' => $activityType->getProviderType(), 'discipline' => $activityType->getDiscipline()->value, 'active' => $activityType->isActive()], $activityTypes);

        return [
            'items' => $itemRows, 'titles' => $titleRows, 'combat' => ['fighter' => $settings->getFighter(), ...$enemyRows], 'loot' => ['version' => $settings->lootVersion(), 'loot_luck' => $settings->getLootLuck(), ...$lootRows],
            'training' => $settings->getTraining(), 'xp' => $settings->getXp(), 'attributes' => $settings->getAttributes(), 'disciplines' => $disciplineRows, 'levels' => $levelRows,
            'activity_types' => $activityRows, 'community' => $settings->getCommunity(), 'notifications' => $settings->getNotifications(),
        ];
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
        self::validateActiveReferences($items, $enemies, $bosses, $workout, $adversary, $chest);
        $lootVersion = $snapshot['loot']['version'];
        \assert(\is_int($lootVersion));
        new LootTables($lootVersion, $workout, $adversary, $chest, $items, $enemies, $bosses);
        /** @var array{minimum_duration_seconds: int, maximum_duration_seconds: int, import_window_days: int} $training */
        $training = $snapshot['training'];
        /** @var array{base_xp_per_hour: int, diminishing_returns: list<array{up_to_minutes: int, weight_percent: int}>, diminishing_returns_beyond_percent: int} $xp */
        $xp = $snapshot['xp'];
        /** @var list<array{discipline: string, credits_xp: bool, daily_cap_xp: ?int, xp_per_km: ?int, xp_per_100m_elevation: ?int, split: ?array<string, int>}> $disciplines */
        $disciplines = $snapshot['disciplines'];
        $rates = array_map(static function (array $discipline): array {
            $rate = ['discipline' => $discipline['discipline']];
            if (!$discipline['credits_xp']) {
                $rate['credits_xp'] = false;
            }
            foreach (['daily_cap_xp', 'xp_per_km', 'xp_per_100m_elevation'] as $key) {
                if (null !== $discipline[$key]) {
                    $rate[$key] = $discipline[$key];
                }
            }

            return $rate;
        }, $disciplines);
        $splits = array_map(static fn (array $discipline): array => ['discipline' => $discipline['discipline'], ...$discipline['split']], array_filter($disciplines, static fn (array $discipline): bool => null !== $discipline['split']));
        new WorkoutRules($training['minimum_duration_seconds'], $training['maximum_duration_seconds'], $training['import_window_days']);
        new XpRates($xp['base_xp_per_hour'], $rates);
        new DiminishingReturns($xp['diminishing_returns'], $xp['diminishing_returns_beyond_percent']);
        new AttributeSplit($splits, $rates);
        /** @var array{vitality: array{floor_permille: int, target_active_kcal: int, bonus_cap_permille: int}} $attributes */
        $attributes = $snapshot['attributes'];
        new Vitality($attributes['vitality']['floor_permille'], $attributes['vitality']['target_active_kcal'], $attributes['vitality']['bonus_cap_permille']);
        /** @var list<array{level: int, total_xp: int, skill_points: int}> $levels */
        $levels = $snapshot['levels'];
        new LevelCurve($levels);
        $activityBySource = ['APPLE_HEALTH' => [], 'HEALTH_CONNECT' => []];
        foreach ($snapshot['activity_types'] as $activityType) {
            \assert(\is_array($activityType));
            if ($activityType['active']) {
                $activityBySource[$activityType['source']][] = ['activity_type' => $activityType['provider_type'], 'discipline' => $activityType['discipline']];
            }
        }
        new ActivityTypeMap($activityBySource['APPLE_HEALTH'], $activityBySource['HEALTH_CONNECT']);
        /** @var array{maximum_members: int, invite_code_lifetime_hours: int, risala: array{active_weeks: int, reveal_day: int, reveal_hour: int, week_timezone: string, recipient_bonus_percent: int, sender_bonus_percent: int}} $community */
        $community = $snapshot['community'];
        new GuildRules($community['maximum_members'], $community['invite_code_lifetime_hours']);
        new RisalaRules(
            $community['risala']['active_weeks'],
            $community['risala']['reveal_day'],
            $community['risala']['reveal_hour'],
            $community['risala']['week_timezone'],
            $community['risala']['recipient_bonus_percent'],
            $community['risala']['sender_bonus_percent'],
        );
        /** @var array{quiet_hours_start_hour: int, quiet_hours_end_hour: int} $notifications */
        $notifications = $snapshot['notifications'];
        new QuietHours($notifications['quiet_hours_start_hour'], $notifications['quiet_hours_end_hour']);
    }

    /**
     * Une désactivation conserve les faits déjà écrits, mais ne doit jamais laisser un
     * chemin actif produire une récompense ou une rencontre devenue inactive.
     *
     * @param list<array<string, mixed>> $items
     * @param list<array<string, mixed>> $enemies
     * @param list<array<string, mixed>> $bosses
     * @param list<array<string, mixed>> $workout
     * @param list<array<string, mixed>> $adversary
     * @param list<array<string, mixed>> $chest
     */
    private static function validateActiveReferences(array $items, array $enemies, array $bosses, array $workout, array $adversary, array $chest): void
    {
        $activeItems = [];
        $activeEnemies = [];
        $activeChests = [];
        foreach ($items as $item) {
            \assert(\is_string($item['key'] ?? null));
            if (($item['active'] ?? true) !== true) {
                continue;
            }
            $activeItems[$item['key']] = true;
            if ('CHEST' === ($item['kind'] ?? 'EQUIPMENT')) {
                $activeChests[$item['key']] = true;
            }
        }
        foreach ([...$enemies, ...$bosses] as $enemy) {
            \assert(\is_string($enemy['key'] ?? null));
            if (($enemy['active'] ?? true) === true) {
                $activeEnemies[$enemy['key']] = true;
            }
        }
        $activeTableKeys = ['adversary' => [], 'chest' => []];

        foreach (['workout' => $workout, 'adversary' => $adversary, 'chest' => $chest] as $kind => $tables) {
            foreach ($tables as $table) {
                \assert(\is_string($table['key'] ?? null));
                if (!($table['active'] ?? true)) {
                    continue;
                }
                if ('adversary' === $kind || 'chest' === $kind) {
                    $activeTableKeys[$kind][$table['key']] = true;
                }
                $entries = $table['entries'] ?? [];
                \assert(\is_array($entries));
                foreach ($entries as $entry) {
                    \assert(\is_array($entry));
                    $itemKey = $entry['item'] ?? null;
                    \assert(null === $itemKey || \is_string($itemKey));
                    if (null !== $itemKey && !isset($activeItems[$itemKey])) {
                        throw new LogicException(\sprintf('La table active "%s" référence l’objet inactif "%s".', $table['key'], $itemKey));
                    }
                }
            }
        }
        foreach ($activeEnemies as $key => $_) {
            if (!isset($activeTableKeys['adversary'][$key])) {
                throw new LogicException(\sprintf('L’adversaire actif "%s" doit avoir une table de tirage active.', $key));
            }
        }
        foreach ($activeTableKeys['adversary'] as $key => $_) {
            if (!isset($activeEnemies[$key])) {
                throw new LogicException(\sprintf('La table adversaire active "%s" doit référencer un adversaire actif.', $key));
            }
        }
        foreach ($activeChests as $key => $_) {
            if (!isset($activeTableKeys['chest'][$key])) {
                throw new LogicException(\sprintf('Le coffre actif "%s" doit avoir une table de tirage active.', $key));
            }
        }
        foreach ($activeTableKeys['chest'] as $key => $_) {
            if (!isset($activeChests[$key])) {
                throw new LogicException(\sprintf('La table coffre active "%s" doit référencer un coffre actif.', $key));
            }
        }
    }

    /**
     * @param array<string, mixed> $snapshot
     *
     * @return array<string, mixed>
     */
    private static function lootGameplay(array $snapshot): array
    {
        $loot = $snapshot['loot'] ?? [];
        \assert(\is_array($loot));
        /** @var array<string, mixed> $loot */

        return $loot;
    }
}
