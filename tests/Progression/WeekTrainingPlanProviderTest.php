<?php

declare(strict_types=1);

namespace App\Tests\Progression;

use App\Progression\Application\WeekProgress;
use App\Progression\Application\WeekTrainingPlan;
use App\Progression\Application\WeekTrainingPlanProvider;
use App\Progression\Application\WeekTrainingSession;
use App\Progression\Domain\WeekTotals;
use App\Progression\Domain\XpAward;
use App\Progression\Domain\XpBreakdown;
use App\Progression\Domain\XpBreakdownLine;
use App\Progression\Domain\XpBreakdownSource;
use App\Progression\Domain\XpCalculator;
use App\Progression\Domain\XpRates;
use App\Progression\Domain\XpTransaction;
use App\Progression\Infrastructure\Doctrine\XpTransactionRepository;
use App\Shared\Application\GameRulesets;
use App\Shared\Application\ModifierResolver;
use App\Shared\Application\PlayerTimezones;
use App\Shared\Domain\Activity\AttributeGains;
use App\Shared\Domain\Activity\AttributeSplit;
use App\Shared\Domain\Activity\CreditingDisciplines;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\LocalDay;
use App\Shared\Domain\Modifier\Modifier;
use App\Shared\Domain\Modifier\ModifierSource;
use App\Shared\Domain\Modifier\ModifierType;
use App\Shared\Domain\Timezone;
use App\Shared\Infrastructure\Config\DatabaseGameRulesets;
use App\Shared\Infrastructure\Config\GameRulesetVersion;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\ProgrammableModifiers;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class WeekTrainingPlanProviderTest extends ApiTestCase
{
    public function testOffersThreeUsefulChoicesWithDifferentSportsAndKeepsThePrincipal(): void
    {
        $player = $this->openAccount()->id;
        $provider = $this->provider();
        $week = self::week(new AttributeGains(10, 10, 0, 0));

        $plans = $provider->plansForWeek($player, $week);

        self::assertCount(3, $plans);
        $seen = [];
        foreach ($plans as $plan) {
            self::assertInstanceOf(WeekTrainingPlan::class, $plan);
            $sports = array_map(static fn (WeekTrainingSession $session): string => $session->discipline->value, $plan->sessions);
            self::assertNotEmpty(array_diff($sports, $seen), 'Chaque choix doit apporter un autre sport, pas quelques minutes de plus.');
            $seen = array_unique([...$seen, ...$sports]);
            self::assertSame(0, $plan->remainingAfter->total());
        }
        $principal = $provider->forWeek($player, $week);
        self::assertNotNull($principal);
        self::assertEquals($plans[0]->sessions, $principal->sessions);
        self::assertEquals($plans[0]->remainingAfter, $principal->remainingAfter);
    }

    public function testOnlyOneEligibleSportDoesNotProduceDurationVariants(): void
    {
        $player = $this->openAccount()->id;
        $plans = $this->provider($this->onlySports(Discipline::Running))->plansForWeek($player, self::week(new AttributeGains(0, 10, 0, 0)));

        self::assertCount(1, $plans);
        self::assertCount(1, $plans[0]->sessions);
        self::assertSame(Discipline::Running, $plans[0]->sessions[0]->discipline);
        self::assertSame(900, $plans[0]->sessions[0]->durationSeconds, 'Le plan complet le plus court garde le classement existant.');
    }

    public function testAnActiveBonusCanShortenTheRecommendedEffort(): void
    {
        $player = $this->openAccount()->id;
        $provider = $this->provider($this->onlySports(Discipline::Running));
        $week = self::week(new AttributeGains(0, 20, 0, 0));
        $withoutBonus = $provider->plansForWeek($player, $week);
        ProgrammableModifiers::grant(new Modifier(ModifierType::XpMultiplier, 100, ModifierSource::Streak));
        $withBonus = $provider->plansForWeek($player, $week);

        self::assertCount(1, $withoutBonus);
        self::assertCount(1, $withBonus);
        self::assertLessThan($withoutBonus[0]->sessions[0]->durationSeconds, $withBonus[0]->sessions[0]->durationSeconds);
        self::assertSame(0, $withBonus[0]->remainingAfter->total());
    }

    public function testAnUnreachableTargetKeepsTheBestPartialCoverageAndCorrectDeficits(): void
    {
        $player = $this->openAccount()->id;
        $missing = new AttributeGains(1000, 1000, 1000, 1000);
        $plans = $this->provider()->plansForWeek($player, self::week($missing));

        self::assertCount(3, $plans);
        foreach ($plans as $plan) {
            self::assertGreaterThan(0, $plan->remainingAfter->total());
            self::assertLessThan($missing->total(), $plan->remainingAfter->total());
            self::assertCount(2, $plan->sessions);
            foreach ($plan->remainingAfter->toArray() as $attribute => $after) {
                $gained = array_sum(array_map(static fn (WeekTrainingSession $s): int => $s->award->attributeGains->toArray()[$attribute], $plan->sessions));
                self::assertSame(max(0, $missing->toArray()[$attribute] - $gained), $after);
            }
        }
    }

    public function testNoTargetCompletedWeekAndNoEligibleSportReturnNoPlan(): void
    {
        $player = $this->openAccount()->id;
        $week = self::week(new AttributeGains(0, 0, 0, 0));
        $provider = $this->provider();
        self::assertSame([], $provider->plansForWeek($player, $week));
        self::assertNull($provider->forWeek($player, $week));
        self::assertSame([], $provider->plansForWeek($player, new WeekProgress($week->startsAt, $week->resetsAt, $week->current, null, [])));
        self::assertSame([], $provider->plansForWeek($player, new WeekProgress($week->startsAt, $week->resetsAt, new WeekTotals(0, 0, new AttributeGains(0, 0, 0, 0)), $week->previous, [])));
        self::assertSame([], $this->provider($this->onlySports())->plansForWeek($player, self::week(new AttributeGains(10, 0, 0, 0))));
    }

    public function testActiveBonusesAndActualDailyLoadsApplyIndependentlyToEveryChoice(): void
    {
        $player = $this->openAccount()->id;
        $rules = $this->onlySports(Discipline::Running, Discipline::Cycling, Discipline::Swimming);
        $rates = XpRates::runtime($rules);
        $calculator = new XpCalculator($rates, AttributeSplit::runtime($rules), $rules);
        $ledger = self::getContainer()->get(XpTransactionRepository::class);
        self::assertInstanceOf(XpTransactionRepository::class, $ledger);
        foreach ([Discipline::Running, Discipline::Cycling, Discipline::Swimming] as $sport) {
            $amount = $rates->dailyCapOf($sport) - 5;
            $ledger->add(XpTransaction::creditFor(
                $player,
                Uuid::v7(),
                $sport,
                3600,
                new XpAward(new XpBreakdown(new XpBreakdownLine(XpBreakdownSource::Base, $amount)), AttributeSplit::runtime($rules)->distribute($sport, $amount), $rules->version()),
                self::now()->modify('-1 hour'),
            ));
        }
        $ledger->commit();
        $bonus = new Modifier(ModifierType::XpMultiplier, 50, ModifierSource::Streak);
        ProgrammableModifiers::grant($bonus);
        $plans = $this->provider($rules)->plansForWeek($player, self::week(new AttributeGains(1000, 1000, 1000, 1000)));

        self::assertCount(2, $plans, 'Trois sports ne justifient pas un troisième choix si les deux premiers les couvrent déjà.');
        $day = LocalDay::containing(self::now(), Timezone::fromString('Europe/Paris'));
        foreach ($plans as $plan) {
            self::assertSame($rules->version(), $plan->rulesetVersion);
            foreach ($plan->sessions as $session) {
                $expected = $calculator->calculate($session->discipline, $session->durationSeconds, [$bonus], $ledger->dailyLoadOf($player, $session->discipline, $day));
                self::assertEquals($expected, $session->award);
                self::assertSame(5, $session->award->amount());
                self::assertContains(XpBreakdownSource::Streak, array_column($session->award->breakdown->lines, 'source'));
            }
        }
    }

    public function testAnExhaustedDailyCapDoesNotInventASolution(): void
    {
        $player = $this->openAccount()->id;
        $rules = $this->onlySports(Discipline::Running);
        $rates = XpRates::runtime($rules);
        $ledger = self::getContainer()->get(XpTransactionRepository::class);
        self::assertInstanceOf(XpTransactionRepository::class, $ledger);
        $ledger->add(XpTransaction::creditFor(
            $player,
            Uuid::v7(),
            Discipline::Running,
            3600,
            new XpAward(new XpBreakdown(new XpBreakdownLine(XpBreakdownSource::Base, $rates->dailyCapOf(Discipline::Running))), new AttributeGains(0, $rates->dailyCapOf(Discipline::Running), 0, 0), $rules->version()),
            self::now(),
        ));
        $ledger->commit();

        self::assertSame([], $this->provider($rules)->plansForWeek($player, self::week(new AttributeGains(0, 10, 0, 0))));
    }

    public function testLocalMidnightAndWeekResetBoundEveryPlanDurationAndFreshness(): void
    {
        $player = $this->openAccount()->id;
        $clock = new MockClock('2026-11-12T23:45:00+01:00');
        $plans = $this->provider(clock: $clock)->plansForWeek($player, self::week(new AttributeGains(1000, 1000, 1000, 1000)));
        self::assertCount(3, $plans);
        foreach ($plans as $plan) {
            self::assertEquals($clock->now(), $plan->calculatedAt);
            self::assertEquals(new DateTimeImmutable('2026-11-13T00:00:00+01:00'), $plan->expiresAt);
            self::assertLessThanOrEqual(900, array_sum(array_column($plan->sessions, 'durationSeconds')));
        }
        $clock = new MockClock(self::now());
        $week = self::week(new AttributeGains(1000, 1000, 1000, 1000));
        $resetSoon = new WeekProgress($week->startsAt, self::now()->modify('+15 minutes'), $week->current, $week->previous, []);
        foreach ($this->provider(clock: $clock)->plansForWeek($player, $resetSoon) as $plan) {
            self::assertEquals($resetSoon->resetsAt, $plan->expiresAt);
            self::assertLessThanOrEqual(900, array_sum(array_column($plan->sessions, 'durationSeconds')));
        }
        $clock->modify('+6 minutes');
        self::assertSame([], $this->provider(clock: $clock)->plansForWeek($player, $resetSoon));
    }

    public function testPublishedRulesStayPinnedUntilTheNextOperationThenRefreshDurationBounds(): void
    {
        $player = $this->openAccount()->id;
        $rules = self::getContainer()->get(GameRulesets::class);
        self::assertInstanceOf(DatabaseGameRulesets::class, $rules);
        $version = $rules->version();
        $snapshot = $rules->snapshot();
        $snapshot['training'] = ['minimum_duration_seconds' => 1200, 'maximum_duration_seconds' => 1500];
        $nextVersion = GameRulesetVersion::of($snapshot);
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $connection->beginTransaction();
        try {
            $connection->executeStatement('UPDATE game_ruleset SET snapshot = ?, version = ?, revision = revision + 1 WHERE id = 1', [json_encode($snapshot, \JSON_THROW_ON_ERROR), $nextVersion]);
            $week = self::week(new AttributeGains(10, 10, 0, 0));
            $plans = $this->provider($rules)->plansForWeek($player, $week);
            self::assertCount(3, $plans);
            foreach ($plans as $plan) {
                self::assertSame($version, $plan->rulesetVersion);
                foreach ($plan->sessions as $session) {
                    self::assertSame($version, $session->award->rulesetVersion);
                }
            }

            // Comme le reset Symfony entre deux requêtes : la nouvelle opération voit la publication.
            $rules->reset();
            $plans = $this->provider($rules)->plansForWeek($player, $week);
            self::assertCount(3, $plans);
            foreach ($plans as $plan) {
                self::assertSame($nextVersion, $plan->rulesetVersion);
                foreach ($plan->sessions as $session) {
                    self::assertSame($nextVersion, $session->award->rulesetVersion);
                    self::assertGreaterThanOrEqual(1200, $session->durationSeconds);
                    self::assertLessThanOrEqual(1500, $session->durationSeconds);
                }
            }
        } finally {
            $connection->rollBack();
            $rules->reset();
        }
    }

    private function provider(?GameRulesets $rules = null, ?MockClock $clock = null): WeekTrainingPlanProvider
    {
        $container = self::getContainer();
        $rules ??= $container->get(GameRulesets::class);
        self::assertInstanceOf(GameRulesets::class, $rules);

        return new WeekTrainingPlanProvider(
            new XpCalculator(XpRates::runtime($rules), AttributeSplit::runtime($rules), $rules),
            CreditingDisciplines::runtime($rules),
            $container->get(ModifierResolver::class),
            $container->get(XpTransactionRepository::class),
            $container->get(PlayerTimezones::class),
            $rules,
            $clock ?? new MockClock(self::now()),
        );
    }

    private function onlySports(Discipline ...$sports): GameRulesets
    {
        $rules = self::getContainer()->get(GameRulesets::class);
        self::assertInstanceOf(GameRulesets::class, $rules);
        $snapshot = $rules->snapshot();
        self::assertIsArray($snapshot['disciplines']);
        foreach ($snapshot['disciplines'] as &$discipline) {
            self::assertIsArray($discipline);
            $discipline['active'] = \in_array($discipline['discipline'], array_column($sports, 'value'), true);
        }

        return new class($snapshot) implements GameRulesets {
            /** @param array<string, mixed> $data */
            public function __construct(private readonly array $data)
            {
            }

            public function snapshot(): array
            {
                return $this->data;
            }

            public function version(): string
            {
                return GameRulesetVersion::of($this->data);
            }

            public function revision(): int
            {
                return 1;
            }
        };
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-12T10:00:00+01:00');
    }

    private static function week(AttributeGains $missing): WeekProgress
    {
        return new WeekProgress(
            self::now()->modify('-1 day'),
            self::now()->modify('+1 day'),
            new WeekTotals(10, 1, new AttributeGains(0, 10, 0, 0)),
            new WeekTotals(10 + $missing->total(), 1, new AttributeGains($missing->strength, 10 + $missing->endurance, $missing->mobility, $missing->dexterity)),
            [],
        );
    }
}
