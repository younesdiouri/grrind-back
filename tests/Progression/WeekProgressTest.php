<?php

declare(strict_types=1);

namespace App\Tests\Progression;

use App\Progression\Application\GrantXp;
use App\Progression\Application\GrantXpHandler;
use App\Progression\Domain\XpReason;
use App\Progression\Domain\XpTransaction;
use App\Progression\Infrastructure\Doctrine\XpTransactionRepository;
use App\Shared\Application\GameRulesets;
use App\Shared\Domain\Activity\CreditingDisciplines;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Alam\AlamCalendar;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Bridge\Doctrine\DataCollector\DoctrineDataCollector;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /api/progression/week` (#348) : les jauges de la semaine contre la semaine d'avant.
 */
final class WeekProgressTest extends ApiTestCase
{
    private GrantXpHandler $grantXp;
    private XpTransactionRepository $ledger;
    /** @var array{start: DateTimeImmutable, close: DateTimeImmutable, reset: DateTimeImmutable} */
    private array $week;

    protected function setUp(): void
    {
        parent::setUp();

        $grantXp = self::getContainer()->get(GrantXpHandler::class);
        self::assertInstanceOf(GrantXpHandler::class, $grantXp);
        $this->grantXp = $grantXp;

        $ledger = self::getContainer()->get(XpTransactionRepository::class);
        self::assertInstanceOf(XpTransactionRepository::class, $ledger);
        $this->ledger = $ledger;

        $rules = self::getContainer()->get(GameRulesets::class);
        self::assertInstanceOf(GameRulesets::class, $rules);
        $this->week = AlamCalendar::runtime($rules)->week(new DateTimeImmutable());
    }

    public function testAnEmptyWeekHasNoTargetToBeat(): void
    {
        $week = self::decode($this->get('/api/progression/week', $this->openAccount()->headers));

        self::assertSame($this->week['start']->format(DateTimeInterface::ATOM), $week['startsAt']);
        self::assertSame($this->week['reset']->format(DateTimeInterface::ATOM), $week['resetsAt']);
        self::assertSame(['xp' => 0, 'sessions' => 0, 'attributes' => ['strength' => 0, 'endurance' => 0, 'mobility' => 0, 'dexterity' => 0]], $week['current']);
        self::assertNull($week['previous'], 'Pas de semaine précédente : le client n\'invente pas de cible.');
        self::assertSame([], $week['entries']);
        self::assertNull($week['trainingPlan']);
        self::assertSame([], $week['trainingPlans']);
    }

    public function testTrainingAlternativesKeepThePrincipalContractAndShareFreshness(): void
    {
        $account = $this->openAccount();
        ($this->grantXp)(new GrantXp($account->id, Uuid::v7(), Discipline::Running, 1800, $this->week['start']->modify('-1 day')));
        ($this->grantXp)(new GrantXp($account->id, Uuid::v7(), Discipline::Running, 600, $this->week['start']->modify('+1 minute')));

        $this->client->enableProfiler();
        $week = self::decode($this->get('/api/progression/week', $account->headers));

        self::assertIsArray($week['trainingPlans']);
        self::assertCount(3, $week['trainingPlans']);
        self::assertSame($week['trainingPlan'], $week['trainingPlans'][0]);
        self::assertIsArray($week['trainingPlan']);
        foreach ($week['trainingPlans'] as $plan) {
            self::assertIsArray($plan);
            foreach (['remaining', 'calculatedAt', 'expiresAt', 'rulesetVersion'] as $key) {
                self::assertSame($week['trainingPlan'][$key], $plan[$key]);
            }
            self::assertSame(['remaining', 'remainingAfter', 'completesWeek', 'calculatedAt', 'expiresAt', 'rulesetVersion', 'sessions'], array_keys($plan));
            self::assertIsArray($plan['sessions']);
            foreach ($plan['sessions'] as $session) {
                self::assertIsArray($session);
                self::assertSame(['discipline', 'durationSeconds', 'estimatedXp', 'estimatedGains'], array_keys($session));
                self::assertIsArray($session['estimatedGains']);
                self::assertSame($session['estimatedXp'], array_sum($session['estimatedGains']));
            }
            self::assertIsArray($plan['remainingAfter']);
            self::assertSame(0 === array_sum($plan['remainingAfter']), $plan['completesWeek']);
        }

        $profile = $this->client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);
        $dailyLoadQueries = 0;
        foreach ($collector->getQueries() as $queries) {
            self::assertIsArray($queries);
            foreach ($queries as $query) {
                self::assertIsArray($query);
                self::assertIsString($query['sql']);
                if (str_contains($query['sql'], 'SUM(') && str_contains($query['sql'], 'xp_transaction') && str_contains($query['sql'], 'discipline =')) {
                    ++$dailyLoadQueries;
                }
            }
        }
        $disciplines = self::getContainer()->get(CreditingDisciplines::class);
        self::assertInstanceOf(CreditingDisciplines::class, $disciplines);
        self::assertSame(\count($disciplines->all()), $dailyLoadQueries, 'Une lecture par sport, quel que soit le nombre de plans.');
        $this->assertOnlyRulesetRevisionPointerSql();
    }

    public function testTheCurrentWeekIsTheSumOfItsEntriesWithoutAPreviousOne(): void
    {
        $account = $this->openAccount();
        $first = ($this->grantXp)(new GrantXp($account->id, Uuid::v7(), Discipline::Running, 1800, $this->week['start']->modify('+1 minute')));
        $second = ($this->grantXp)(new GrantXp($account->id, Uuid::v7(), Discipline::Cycling, 1800, new DateTimeImmutable()));

        $week = self::decode($this->get('/api/progression/week', $account->headers));

        self::assertNull($week['previous']);
        self::assertIsArray($week['current']);
        self::assertSame($first->award->amount() + $second->award->amount(), $week['current']['xp']);
        self::assertSame(2, $week['current']['sessions']);
        self::assertSame(
            ['strength' => $first->award->attributeGains->strength + $second->award->attributeGains->strength,
                'endurance' => $first->award->attributeGains->endurance + $second->award->attributeGains->endurance,
                'mobility' => $first->award->attributeGains->mobility + $second->award->attributeGains->mobility,
                'dexterity' => $first->award->attributeGains->dexterity + $second->award->attributeGains->dexterity],
            $week['current']['attributes'],
        );

        // La plus récente d'abord.
        self::assertIsArray($week['entries']);
        self::assertCount(2, $week['entries']);
        self::assertIsArray($week['entries'][0]);
        self::assertSame('CYCLING', $week['entries'][0]['discipline']);
    }

    public function testALastWeekSessionImportedThisWeekCountsForLastWeek(): void
    {
        $account = $this->openAccount();
        $lastWeek = ($this->grantXp)(new GrantXp($account->id, Uuid::v7(), Discipline::Running, 3600, $this->week['start']->modify('-1 day')));
        // Juste avant la remise à zéro : toujours la semaine d'avant.
        ($this->grantXp)(new GrantXp($account->id, Uuid::v7(), Discipline::Cycling, 600, $this->week['start']->modify('-2 hours')));
        $before = $this->ledger->totalsBetween($account->id, $this->week['start']->modify('-7 days'), $this->week['start']);

        $week = self::decode($this->get('/api/progression/week', $account->headers));

        self::assertSame([], $week['entries']);
        self::assertSame(0, self::totals($week, 'current')['xp']);
        self::assertSame(2, self::totals($week, 'previous')['sessions']);
        self::assertSame($before->xp, self::totals($week, 'previous')['xp']);
        self::assertGreaterThan($lastWeek->award->amount(), $before->xp);
    }

    public function testAnInvalidationLowersTheWeekAndIsListed(): void
    {
        $account = $this->openAccount();
        $sessionId = Uuid::v7();
        ($this->grantXp)(new GrantXp($account->id, $sessionId, Discipline::Running, 3600, new DateTimeImmutable()));
        $kept = ($this->grantXp)(new GrantXp($account->id, Uuid::v7(), Discipline::Cycling, 1800, new DateTimeImmutable()));

        $credit = $this->ledger->recordedFor($sessionId, XpReason::SessionCompleted);
        self::assertInstanceOf(XpTransaction::class, $credit);
        $this->ledger->add(XpTransaction::reversalOf($credit));
        $this->ledger->commit();

        $week = self::decode($this->get('/api/progression/week', $account->headers));

        self::assertSame($kept->award->amount(), self::totals($week, 'current')['xp']);
        self::assertSame(1, self::totals($week, 'current')['sessions']);
        self::assertIsArray($week['entries']);
        self::assertCount(3, $week['entries']);
        self::assertContains('SESSION_INVALIDATED', array_column($week['entries'], 'reason'));
    }

    public function testDoesNotShowAnotherAccount(): void
    {
        $mine = $this->openAccount();
        $theirs = $this->openAccount('alice@grrind.app', 'Alice');
        ($this->grantXp)(new GrantXp($theirs->id, Uuid::v7(), Discipline::Running, 3600, new DateTimeImmutable()));

        self::assertSame([], self::decode($this->get('/api/progression/week', $mine->headers))['entries']);
    }

    public function testRequiresAToken(): void
    {
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->get('/api/progression/week')->getStatusCode());
    }

    /**
     * @param array<mixed> $week
     *
     * @return array<mixed>
     */
    private static function totals(array $week, string $key): array
    {
        self::assertIsArray($week[$key]);

        return $week[$key];
    }
}
