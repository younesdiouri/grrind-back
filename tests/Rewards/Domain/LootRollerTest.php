<?php

declare(strict_types=1);

namespace App\Tests\Rewards\Domain;

use App\Rewards\Domain\LootLuckRules;
use App\Rewards\Domain\LootRoller;
use App\Rewards\Domain\LootTables;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Modifier\Modifier;
use App\Shared\Domain\Modifier\ModifierSource;
use App\Shared\Domain\Modifier\ModifierType;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Le moteur seul, sans base ni conteneur — voir le docblock de {@see LootRoller} : une
 * table (ou les tables du jeu, pour l'éligibilité), les modificateurs déjà résolus et un
 * `Randomizer` grainé entrent, un {@see \App\Rewards\Domain\LootRollOutcome} sort.
 */
final class LootRollerTest extends TestCase
{
    public function testTheSameSeedProducesAnIdenticalOutcome(): void
    {
        $roller = self::rollerOf();
        $tables = self::tablesOf([self::workout('TIER_ONE', [], 0, 1)], []);

        $first = $roller->rollForWorkout($tables, Discipline::Running, 30, 1, [], self::randomizer('même graine'));
        $second = $roller->rollForWorkout($tables, Discipline::Running, 30, 1, [], self::randomizer('même graine'));

        self::assertEquals($first, $second);
    }

    public function testASessionUnderEveryTableSThresholdRollsNothingAtAllNotEvenCoins(): void
    {
        $roller = self::rollerOf();
        $tables = self::tablesOf([self::workout('TIER_ONE', [], 20, 1)], []);

        // 5 minutes : sous les 20 minutes de la seule table livrée — aucune table n'est
        // éligible, et la bande de pièces appartient à la table, pas à la séance (#28,
        // ajouté après le #27) : rien à tirer du tout.
        $outcome = $roller->rollForWorkout($tables, Discipline::Running, 5, 1, [], self::randomizer());

        self::assertNull($outcome);
    }

    public function testAnAdversaryWithoutADedicatedTableRollsNothing(): void
    {
        $roller = self::rollerOf();
        // `SAND_JACKAL` existe dans le catalogue d'adversaires mais n'a volontairement pas
        // de table dans `loot.yaml` — le cas que `RewardsCoverageTest` ne rencontre jamais
        // en production, mais que le roller doit couvrir (#28, ajouté après le #27).
        $tables = self::tablesOf([], [], ['SAND_JACKAL']);

        $outcome = $roller->rollForAdversary($tables, 'SAND_JACKAL', [], self::randomizer());

        self::assertNull($outcome);
    }

    /**
     * Les seuils de `loot.yaml` se recouvrent volontairement : une séance peut être
     * éligible à plusieurs tables à la fois. Le roller retient la plus exigeante — le
     * niveau minimal le plus haut, puis à égalité la durée minimale la plus haute — voir le
     * docblock de {@see LootRoller}.
     */
    public function testTheMostDemandingEligibleWorkoutTableWins(): void
    {
        $roller = self::rollerOf();
        $tables = self::tablesOf([
            self::workout('STARTER', [], 20, 1),
            self::workout('TRAINED', [], 45, 5),
            self::workout('VETERAN', [], 60, 20),
        ], []);

        // Niveau 25, 70 minutes : éligible aux trois, la plus exigeante (VETERAN) gagne.
        $outcome = $roller->rollForWorkout($tables, Discipline::Running, 70, 25, [], self::randomizer());
        self::assertNotNull($outcome);
        self::assertSame('VETERAN', $outcome->tableKey);

        // Niveau 5, 50 minutes : éligible à STARTER et TRAINED (niveau 20 de VETERAN hors
        // de portée), la plus exigeante des deux (TRAINED) gagne.
        $outcome = $roller->rollForWorkout($tables, Discipline::Running, 50, 5, [], self::randomizer());
        self::assertNotNull($outcome);
        self::assertSame('TRAINED', $outcome->tableKey);

        // Niveau 1, 20 minutes : seule STARTER est éligible.
        $outcome = $roller->rollForWorkout($tables, Discipline::Running, 20, 1, [], self::randomizer());
        self::assertNotNull($outcome);
        self::assertSame('STARTER', $outcome->tableKey);
    }

    /**
     * À niveau minimal égal, c'est la durée minimale la plus haute qui départage — voir le
     * docblock de {@see LootRoller}.
     */
    public function testATieOnTheMinimumLevelIsBrokenByTheMinimumDuration(): void
    {
        $roller = self::rollerOf();
        $tables = self::tablesOf([
            self::workout('SHORT', [], 30, 10),
            self::workout('LONG', [], 50, 10),
        ], []);

        $outcome = $roller->rollForWorkout($tables, Discipline::Running, 60, 15, [], self::randomizer());

        self::assertNotNull($outcome);
        self::assertSame('LONG', $outcome->tableKey);
    }

    public function testCoinsAlwaysStayWithinTheTablesBand(): void
    {
        $roller = self::rollerOf();
        $tables = self::tablesOf([self::workout('TIER_ONE', [], 0, 1, minimumCoins: 5, maximumCoins: 7)], []);

        for ($i = 0; $i < 50; ++$i) {
            $outcome = $roller->rollForWorkout($tables, Discipline::Running, 30, 1, [], self::randomizer('graine-'.$i));

            self::assertNotNull($outcome);
            self::assertGreaterThanOrEqual(5, $outcome->coins);
            self::assertLessThanOrEqual(7, $outcome->coins);
        }
    }

    public function testATableWithNoItemEntryNeverRollsAnItem(): void
    {
        $roller = self::rollerOf();
        // Une seule entrée, « rien » — aucun objet à tirer, quel que soit le roll.
        $tables = self::tablesOf([[
            'key' => 'EMPTY',
            'eligibility' => ['disciplines' => [], 'minimum_duration_minutes' => 0, 'minimum_level' => 1],
            'coins' => ['minimum' => 1, 'maximum' => 1],
            'entries' => [['weight' => 100]],
        ]], []);

        for ($i = 0; $i < 10; ++$i) {
            $outcome = $roller->rollForWorkout($tables, Discipline::Running, 0, 1, [], self::randomizer('graine-'.$i));

            self::assertNotNull($outcome);
            self::assertSame([], $outcome->items);
        }
    }

    public function testATableWhereNothingHasNoWeightAlwaysRollsTheItem(): void
    {
        $roller = self::rollerOf();
        // L'entrée « rien » existe (obligatoire) mais à poids nul : elle ne peut jamais
        // être choisie, l'objet sort donc à chaque tirage.
        $tables = self::tablesOf([[
            'key' => 'GUARANTEED',
            'eligibility' => ['disciplines' => [], 'minimum_duration_minutes' => 0, 'minimum_level' => 1],
            'coins' => ['minimum' => 1, 'maximum' => 1],
            'entries' => [['weight' => 0], ['item' => 'ITEM', 'weight' => 100]],
        ]], []);

        for ($i = 0; $i < 10; ++$i) {
            $outcome = $roller->rollForWorkout($tables, Discipline::Running, 0, 1, [], self::randomizer('graine-'.$i));

            self::assertNotNull($outcome);
            self::assertSame(['ITEM'], $outcome->items);
        }
    }

    /**
     * Deux sources de `LOOT_LUCK` actives à la fois composent par somme — même choix et
     * même raison que {@see \App\Combat\Application\FighterFactory::sumOf()}.
     */
    public function testSeveralActiveLootLuckModifiersSumUp(): void
    {
        $roller = self::rollerOf(floorPercent: 0, capPercent: 200);
        $tables = self::tablesOf([self::workout('TIER_ONE', [], 0, 1)], []);

        $outcome = $roller->rollForWorkout(
            $tables,
            Discipline::Running,
            30,
            1,
            [self::lootLuck(30), self::lootLuck(50)],
            self::randomizer(),
        );

        self::assertNotNull($outcome);
        self::assertSame(80, $outcome->effectiveLootLuckPercent);
    }

    /**
     * Le plafond, sans exception : un empilement de `LOOT_LUCK` bien au-delà du plafond
     * livré ne le franchit jamais — voir le docblock de {@see LootLuckRules}.
     */
    public function testLootLuckNeverExceedsItsConfiguredCap(): void
    {
        $roller = self::rollerOf(floorPercent: 0, capPercent: 200);
        $tables = self::tablesOf([self::workout('TIER_ONE', [], 0, 1)], []);

        $hugeStack = array_map(self::lootLuck(...), array_fill(0, 5, 100));

        $outcome = $roller->rollForWorkout($tables, Discipline::Running, 30, 1, $hugeStack, self::randomizer());

        self::assertNotNull($outcome);
        self::assertSame(200, $outcome->effectiveLootLuckPercent);
    }

    /**
     * Le plancher, symétriquement : une somme négative — aucun malus n'existe encore, mais
     * le calcul ne doit pas s'effondrer le jour où un en composera un — ne descend jamais
     * sous le plancher livré.
     */
    public function testLootLuckNeverGoesBelowItsConfiguredFloor(): void
    {
        $roller = self::rollerOf(floorPercent: 0, capPercent: 200);
        $tables = self::tablesOf([self::workout('TIER_ONE', [], 0, 1)], []);

        $outcome = $roller->rollForWorkout($tables, Discipline::Running, 30, 1, [self::lootLuck(-1000)], self::randomizer());

        self::assertNotNull($outcome);
        self::assertSame(0, $outcome->effectiveLootLuckPercent);
    }

    /**
     * `LOOT_LUCK` déplace le poids des entrées à objet, jamais celui de l'entrée « rien » —
     * voir le docblock de {@see LootRoller}. Preuve exacte, sans tirage : le poids total
     * rendu dans l'issue est celui que la formule prédit, entier tronqué.
     */
    public function testLootLuckIncreasesTheItemWeightsButNeverTheNothingWeight(): void
    {
        $roller = self::rollerOf(floorPercent: 0, capPercent: 200);
        // « rien » à 90, un objet à 10 : 100 au total sans LOOT_LUCK.
        $tables = self::tablesOf([[
            'key' => 'TIER_ONE',
            'eligibility' => ['disciplines' => [], 'minimum_duration_minutes' => 0, 'minimum_level' => 1],
            'coins' => ['minimum' => 1, 'maximum' => 1],
            'entries' => [['weight' => 90], ['item' => 'ITEM', 'weight' => 10]],
        ]], []);

        $withoutLuck = $roller->rollForWorkout($tables, Discipline::Running, 0, 1, [], self::randomizer());
        self::assertNotNull($withoutLuck);
        self::assertSame(100, $withoutLuck->itemTotalWeight);

        // +200 % (le plafond livré) sur le poids de l'objet : 10 + floor(10 × 200 / 100) =
        // 30. « rien » reste à 90. Total : 120.
        $withLuck = $roller->rollForWorkout($tables, Discipline::Running, 0, 1, [self::lootLuck(200)], self::randomizer());
        self::assertNotNull($withLuck);
        self::assertSame(120, $withLuck->itemTotalWeight);
    }

    /**
     * La preuve par l'observation, complémentaire du calcul exact ci-dessus : sur un grand
     * nombre de graines indépendantes, la proportion de tirages qui rendent l'objet est
     * nettement plus haute avec `LOOT_LUCK` au plafond que sans — la marge est large pour
     * qu'aucun aléa de test ne fasse flancher l'assertion.
     */
    public function testLootLuckMovesTheObservedDistributionTowardTheItem(): void
    {
        $roller = self::rollerOf(floorPercent: 0, capPercent: 200);
        $tables = self::tablesOf([[
            'key' => 'TIER_ONE',
            'eligibility' => ['disciplines' => [], 'minimum_duration_minutes' => 0, 'minimum_level' => 1],
            'coins' => ['minimum' => 1, 'maximum' => 1],
            'entries' => [['weight' => 90], ['item' => 'ITEM', 'weight' => 10]],
        ]], []);

        $trials = 2000;
        $itemsWithoutLuck = 0;
        $itemsWithLuck = 0;

        for ($i = 0; $i < $trials; ++$i) {
            $withoutLuck = $roller->rollForWorkout($tables, Discipline::Running, 0, 1, [], self::randomizer('sans-chance-'.$i));
            self::assertNotNull($withoutLuck);
            if ([] !== $withoutLuck->items) {
                ++$itemsWithoutLuck;
            }

            $withLuck = $roller->rollForWorkout($tables, Discipline::Running, 0, 1, [self::lootLuck(200)], self::randomizer('avec-chance-'.$i));
            self::assertNotNull($withLuck);
            if ([] !== $withLuck->items) {
                ++$itemsWithLuck;
            }
        }

        // Attendu : ~10 % sans chance (200 tirages), ~25 % avec (500 tirages) — une marge
        // large sépare les deux pour ne jamais dépendre d'un tirage de graines malchanceux.
        self::assertGreaterThan($itemsWithoutLuck * 1.5, $itemsWithLuck);
    }

    /**
     * @param list<array{key: string, eligibility: array{disciplines: list<string>, minimum_duration_minutes: int, minimum_level: int}, coins: array{minimum: int, maximum: int}, entries: list<array{item?: string, weight: int}>}> $workout
     * @param list<array{key: string, coins: array{minimum: int, maximum: int}, entries: list<array{item?: string, weight: int}>}>                                                                                                   $adversary
     * @param list<string>                                                                                                                                                                                                           $adversaryKeysWithoutTable clés d'adversaires connues du catalogue, sans table dans `loot.yaml` — voir `testAnAdversaryWithoutADedicatedTableRollsNothing`
     */
    private static function tablesOf(array $workout, array $adversary, array $adversaryKeysWithoutTable = []): LootTables
    {
        /** @var list<array{key: string}> $enemies */
        $enemies = [
            ...array_map(static fn (array $entry): array => ['key' => $entry['key']], $adversary),
            ...array_map(static fn (string $key): array => ['key' => $key], $adversaryKeysWithoutTable),
        ];

        return new LootTables(1, $workout, $adversary, [['key' => 'ITEM']], $enemies, []);
    }

    /**
     * @param list<string> $disciplines
     *
     * @return array{key: string, eligibility: array{disciplines: list<string>, minimum_duration_minutes: int, minimum_level: int}, coins: array{minimum: int, maximum: int}, entries: list<array{item?: string, weight: int}>}
     */
    private static function workout(string $key, array $disciplines, int $minimumDurationMinutes, int $minimumLevel, int $minimumCoins = 5, int $maximumCoins = 15): array
    {
        return [
            'key' => $key,
            'eligibility' => [
                'disciplines' => $disciplines,
                'minimum_duration_minutes' => $minimumDurationMinutes,
                'minimum_level' => $minimumLevel,
            ],
            'coins' => ['minimum' => $minimumCoins, 'maximum' => $maximumCoins],
            'entries' => [['weight' => 70], ['item' => 'ITEM', 'weight' => 30]],
        ];
    }

    private static function lootLuck(int $value): Modifier
    {
        return new Modifier(ModifierType::LootLuck, $value, ModifierSource::Item);
    }

    private static function rollerOf(int $floorPercent = 0, int $capPercent = 200): LootRoller
    {
        return new LootRoller(new LootLuckRules($floorPercent, $capPercent));
    }

    /**
     * `Xoshiro256StarStar` exige exactement 32 octets de graine — `sha256` en binaire en
     * rend toujours pile ce compte, même geste que `BattleSimulatorTest`.
     */
    private static function randomizer(string $seed = 'seed-de-test'): Randomizer
    {
        return new Randomizer(new Xoshiro256StarStar(hash('sha256', $seed, true)));
    }
}
