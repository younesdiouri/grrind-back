<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Domain\GameDesignCampaign;
use App\Admin\Domain\GameDesignCombat;
use App\Admin\Domain\GameDesignProfileGenerator;
use App\Admin\Domain\GameDesignStatistics;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

/** @phpstan-import-type Observation from GameDesignStatistics */
final class GameDesignCampaignTest extends TestCase
{
    public function testGenerationIsReproducibleDiverseAndConservesEveryBudget(): void
    {
        $generator = new GameDesignProfileGenerator();
        foreach ([1, 7, 10000, 100000000] as $budget) {
            $profiles = $generator->generate(20, $budget, 42);
            self::assertSame($profiles, $generator->generate(20, $budget, 42));
            foreach ($profiles as $profile) {
                $values = [$profile['strength'], $profile['endurance'], $profile['mobility'], $profile['dexterity']];
                self::assertSame($budget, array_sum($values));
                self::assertGreaterThanOrEqual(0, min($values));
                self::assertSame([], $profile['equipment']);
                self::assertNull($profile['vitalityOverride']);
            }
        }
        $profiles = $generator->generate(20, 10000, 42);
        self::assertGreaterThanOrEqual(7000, $profiles[0]['strength']);
        self::assertGreaterThanOrEqual(7000, $profiles[4]['endurance']);
        self::assertGreaterThanOrEqual(7000, $profiles[8]['mobility']);
        self::assertGreaterThanOrEqual(7000, $profiles[12]['dexterity']);
        self::assertNotSame($profiles, $generator->generate(20, 10000, 43));
        $this->expectException(InvalidArgumentException::class);
        $generator->generate(51, 100, 42);
    }

    public function testWilsonIntervalsAndWeightedAggregatesIncludeZeroAndFullVictory(): void
    {
        self::assertEqualsWithDelta(27.75328, GameDesignStatistics::wilson(0, 10)['high'], 0.0001);
        self::assertEqualsWithDelta(0, GameDesignStatistics::wilson(0, 10)['low'], 0.0001);
        self::assertEqualsWithDelta(72.24672, GameDesignStatistics::wilson(10, 10)['low'], 0.0001);
        self::assertEqualsWithDelta(100, GameDesignStatistics::wilson(10, 10)['high'], 0.0001);
        self::assertEqualsWithDelta(40.38315, GameDesignStatistics::wilson(50, 100)['low'], 0.0001);
        $observations = array_fill(0, 9, ['win' => true, 'limit' => false, 'ticks' => 10, 'playerHpPercent' => 50.0, 'enemyHpPercent' => 0.0]);
        $observations[] = ['win' => false, 'limit' => true, 'ticks' => 100, 'playerHpPercent' => 0.0, 'enemyHpPercent' => 90.0];
        $stats = GameDesignStatistics::summarize($observations);
        self::assertSame(90.0, $stats['winRate']);
        self::assertSame(45.0, $stats['meanPlayerHpPercent']);
        self::assertSame(9.0, $stats['meanEnemyHpPercent']);
        self::assertSame(19.0, $stats['meanTicks']);
        self::assertSame(10, $stats['medianTicks']);
        self::assertSame(10, $stats['p90Ticks']);
        self::assertSame(10.0, $stats['limitRate']);
        self::assertSame(9, $stats['hpHistogram'][5]);
        self::assertSame(1, $stats['hpHistogram'][0]);
        $this->expectException(InvalidArgumentException::class);
        GameDesignStatistics::wilson(0, 0);
    }

    public function testCampaignUsesTheExactEngineAndCommonInputs(): void
    {
        $snapshots = ['published' => GameDesignFixture::snapshot(), 'draft' => GameDesignFixture::snapshot()];
        $profiles = new GameDesignProfileGenerator()->generate(4, 10000, 42);
        $engine = new GameDesignCombat();
        $campaign = new GameDesignCampaign($engine);
        $result = $campaign->run($snapshots, $profiles, ['test'], 10, 42, 65);
        self::assertSame($result, $campaign->run($snapshots, $profiles, ['test'], 10, 42, 65));
        /** @var array{global:array<string,array{n:int}>, cells:list<array{published:array<string,mixed>,draft:array<string,mixed>}>} $result */
        self::assertSame($result['global']['published'], $result['global']['draft']);
        self::assertSame(40, $result['global']['draft']['n']);
        $single = $engine->compare($snapshots['published'], $snapshots['draft'], $profiles[0], 'test', null, 10, 42, false);
        /** @var non-empty-list<Observation> $observations */
        $observations = $single['published']['observations'];
        self::assertSame(GameDesignStatistics::summarize($observations), $result['cells'][0]['published']);
        self::assertSame($result['cells'][0]['published'], $result['cells'][0]['draft']);
    }

    public function testDuelCeilingAndMissingEquipmentAreCheckedAcrossSnapshots(): void
    {
        $snapshot = GameDesignFixture::snapshot();
        /** @var array{combat:array{fighter:array<string,int>}} $snapshot */
        $snapshot['combat']['fighter']['max_attacks'] = 1;
        $snapshots = ['published' => $snapshot, 'draft' => $snapshot];
        self::assertSame(20, GameDesignCampaign::maximumRepetitions($snapshots, 50, 10));
        $profiles = new GameDesignProfileGenerator()->generate(1, 10000, 42);
        $profile = $profiles[0] ?? throw new LogicException('Le lot doit contenir un profil.');
        $profile['equipment'] = ['missing-item'];
        $this->expectException(InvalidArgumentException::class);
        new GameDesignCampaign(new GameDesignCombat())->run($snapshots, [$profile], ['test'], 1, 42, null);
    }

    public function testBudgetIsGlobalAndInvalidReferencesFailBeforeRunning(): void
    {
        $snapshot = GameDesignFixture::snapshot();
        /** @var array{combat:array{fighter:array<string,int>}} $snapshot */
        $snapshot['combat']['fighter']['max_attacks'] = 10000;
        $snapshots = ['published' => $snapshot, 'draft' => $snapshot];
        self::assertSame(1, GameDesignCampaign::maximumRepetitions($snapshots, 20, 1));
        self::assertSame(0, GameDesignCampaign::maximumRepetitions($snapshots, 20, 2));
        $campaign = new GameDesignCampaign(new GameDesignCombat());
        $profiles = new GameDesignProfileGenerator()->generate(20, 10000, 42);
        try {
            $campaign->run($snapshots, $profiles, ['test'], 2, 42, null);
            self::fail('Le budget ne doit pas être réinitialisé pour chaque profil.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('budget', $exception->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Aucun duel lancé');
        $campaign->run($snapshots, $profiles, ['missing'], 1, 42, null);
    }
}
