<?php

declare(strict_types=1);

namespace App\Tests\Engagement;

use App\Engagement\Application\LeagueModifiers;
use App\Engagement\Application\LeagueStateProvider;
use App\Engagement\Application\SettleLeagues;
use App\Engagement\Application\SettleLeaguesHandler;
use App\Engagement\Domain\LeagueWeek;
use App\Engagement\Infrastructure\Doctrine\LeagueRepository;
use App\Progression\Domain\XpAward;
use App\Progression\Domain\XpBreakdown;
use App\Progression\Domain\XpBreakdownLine;
use App\Progression\Domain\XpBreakdownSource;
use App\Progression\Domain\XpTransaction;
use App\Shared\Application\GameRulesets;
use App\Shared\Application\ModifierResolver;
use App\Shared\Domain\Activity\AttributeGains;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Modifier\ModifierSource;
use App\Shared\Domain\Modifier\ModifierType;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class LeagueTest extends ApiTestCase
{
    public function testNewProfilesAreBronzeAndAnonymousAccessIsRefused(): void
    {
        self::assertSame(401, $this->get('/api/league')->getStatusCode());
        $player = $this->openAccount();
        $state = $this->state($player);
        self::assertSame(['tier' => 'BRONZE', 'tierOrder' => 0, 'lootLuckPercent' => 0], $state['league']);
        self::assertFalse($state['isParticipating']);
        self::assertSame(0, $state['score']);
        self::assertNull($state['rank']);
        self::assertNull($state['lastResult']);
        self::assertSame($state['league'], self::decode($this->get('/api/me', $player->headers))['league']);
        self::assertSame($state['league'], self::decode($this->get('/api/players/'.$player->id, $player->headers))['league']);
        $stranger = $this->openAccount('stranger@example.com');
        self::assertSame(404, $this->get('/api/players/'.$player->id, $stranger->headers)->getStatusCode());
    }

    public function testScoresUseTheSportsWeekAndReversalsRatherThanLifetimeXp(): void
    {
        $player = $this->openAccount();
        $week = LeagueWeek::containing(new DateTimeImmutable());
        $this->credit($player, $week->startsAt->modify('-1 second'), 10000);
        $reversed = $this->credit($player, $week->startsAt, 100);
        $this->credit($player, $week->startsAt->modify('+1 hour'), 20);
        $this->credit($player, $week->endsAt, 20000);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist(XpTransaction::reversalOf($reversed));
        $em->flush();
        $state = $this->state($player);
        self::assertSame(20, $state['score']);
        self::assertSame(1, $state['rank']);
        self::assertSame('PROMOTED', $state['projectedMovement']);
    }

    public function testSettlementIsIdempotentAndHistoricalLuckUsesTheSportsDate(): void
    {
        $player = $this->openAccount();
        $week = LeagueWeek::containing(new DateTimeImmutable('monday last week 00:00 Europe/Paris'));
        $repo = self::getContainer()->get(LeagueRepository::class);
        $repo->open($week);
        $credit = $this->credit($player, $week->startsAt, 100);
        $this->settleAt($week->endsAt);
        $this->settleAt($week->endsAt);
        $db = self::getContainer()->get(Connection::class);
        self::assertSame(1, $db->fetchOne('SELECT COUNT(*) FROM engagement_league_result'));
        $state = $this->state($player);
        self::assertSame('SILVER', $state['league']['tier']);
        self::assertIsArray($state['lastResult']);
        self::assertSame('PROMOTED', $state['lastResult']['movement']);
        self::assertSame(100, $state['lastResult']['score']);
        self::assertSame(0, $state['score']);
        self::assertSame('RELEGATED', $state['projectedMovement']);
        $modifiers = self::getContainer()->get(LeagueModifiers::class);
        self::assertSame([], $modifiers->modifiersOf($player->id, $week->startsAt));
        $luck = $modifiers->modifiersOf($player->id, $week->endsAt);
        self::assertCount(1, $luck);
        self::assertSame(ModifierType::LootLuck, $luck[0]->type);
        self::assertSame(5, $luck[0]->value);
        self::assertSame(ModifierSource::League, $luck[0]->source);
        self::assertContains(ModifierSource::League, array_map(static fn ($m) => $m->source, self::getContainer()->get(ModifierResolver::class)->of($player->id, $week->endsAt)));

        // Le ledger reste juste, mais les promotions acquises ne se recalculent pas après clôture.
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist(XpTransaction::reversalOf($credit));
        $em->flush();
        $this->credit($player, $week->startsAt->modify('+1 day'), 999);
        $this->settleAt($week->endsAt);
        $afterLateImport = $this->state($player);
        self::assertIsArray($afterLateImport['lastResult']);
        self::assertSame(100, $afterLateImport['lastResult']['score']);
    }

    public function testAWorkerCatchesUpMissedWeeksAndAnInactivePlayerCannotFallBelowBronze(): void
    {
        $player = $this->openAccount();
        $now = LeagueWeek::containing(new DateTimeImmutable())->startsAt;
        $first = LeagueWeek::containing($now->modify('-3 weeks'));
        self::getContainer()->get(LeagueRepository::class)->open($first);
        $this->credit($player, $first->startsAt, 50);
        $this->settleAt($now);
        $db = self::getContainer()->get(Connection::class);
        self::assertSame(3, $db->fetchOne('SELECT COUNT(*) FROM engagement_league_result'));
        self::assertSame(['SILVER', 'BRONZE', 'BRONZE'], $db->fetchFirstColumn('SELECT next_tier FROM engagement_league_result ORDER BY starts_at'));
        self::assertSame(1, $db->fetchOne('SELECT COUNT(*) FROM engagement_league_period WHERE settled_at IS NULL'));
        self::assertSame('BRONZE', $this->state($player)['league']['tier']);
    }

    public function testTwoWorkersCannotCloseTheSamePeriodConcurrently(): void
    {
        $db = self::getContainer()->get(Connection::class);
        $week = LeagueWeek::containing(new DateTimeImmutable());
        $repo = self::getContainer()->get(LeagueRepository::class);
        $repo->open($week);
        /** @var array{driver: 'pdo_pgsql', host: string, port?: int, user: string, password: string, dbname: string, serverVersion?: string} $parameters */
        $parameters = $db->getParams();
        $second = DriverManager::getConnection($parameters);
        try {
            $db->beginTransaction();
            self::assertNotNull($repo->lockOpenPeriod());
            $second->executeStatement("SET lock_timeout = '50ms'");
            try {
                new LeagueRepository($second)->lockOpenPeriod();
                self::fail('Un second worker a obtenu la période avant le COMMIT.');
            } catch (Exception $exception) {
                self::assertStringContainsString('lock timeout', strtolower($exception->getMessage()));
            }
            $repo->settle($week, [], $week->endsAt, 'league-lock-test');
            $db->commit();
            $next = new LeagueRepository($second)->lockOpenPeriod();
            self::assertNotNull($next);
            self::assertEquals($week->endsAt, new DateTimeImmutable($next['starts_at']));
        } finally {
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }
            $second->close();
        }
    }

    /** @return array{league: array<string, mixed>, lastResult: ?array<string, mixed>, score: int, rank: ?int, isParticipating: bool, projectedMovement: string} */
    private function state(Account $player): array
    {
        $response = $this->get('/api/league', $player->headers);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{league: array<string, mixed>, lastResult: ?array<string, mixed>, score: int, rank: ?int, isParticipating: bool, projectedMovement: string} $body */
        $body = self::decode($response);

        return $body;
    }

    private function settleAt(DateTimeImmutable $now): void
    {
        $container = self::getContainer();
        $handler = new SettleLeaguesHandler($container->get(LeagueRepository::class), $container->get(LeagueStateProvider::class), $container->get(GameRulesets::class), $container->get(Connection::class), new MockClock($now));
        $handler(new SettleLeagues());
    }

    private function credit(Account $player, DateTimeImmutable $at, int $xp): XpTransaction
    {
        $award = new XpAward(new XpBreakdown(new XpBreakdownLine(XpBreakdownSource::Base, $xp)), new AttributeGains(0, $xp, 0, 0), 'league-test');
        $credit = XpTransaction::creditFor($player->id, Uuid::v7(), Discipline::Running, 1800, $award, $at);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($credit);
        $em->flush();

        return $credit;
    }
}
