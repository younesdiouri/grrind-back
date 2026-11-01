<?php

declare(strict_types=1);

namespace App\Tests\Progression;

use App\Progression\Domain\LevelCurve;
use App\Progression\Domain\ProgressionSnapshot;
use App\Progression\Domain\XpAward;
use App\Progression\Domain\XpBreakdown;
use App\Progression\Domain\XpBreakdownLine;
use App\Progression\Domain\XpBreakdownSource;
use App\Progression\Domain\XpTransaction;
use App\Progression\Infrastructure\Doctrine\ProgressionSnapshotRepository;
use App\Progression\Infrastructure\Doctrine\XpTransactionRepository;
use App\Shared\Application\GameRulesets;
use App\Shared\Domain\Activity\AttributeGains;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Activity\Vitality;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class WeeklyCharacteristicsTest extends ApiTestCase
{
    public function testAnOldImportedWorkoutAwardsXpWithoutRefillingCurrentGauges(): void
    {
        $account = $this->openAccount();
        $start = new DateTimeImmutable('-8 days');
        $response = $this->post('/api/workouts/import', ['workouts' => [['externalId' => 'previous-week', 'activityType' => 'running', 'source' => 'APPLE_HEALTH', 'startedAt' => $start->format('c'), 'endedAt' => $start->modify('+30 minutes')->format('c')]]], $account->headers + ['Idempotency-Key' => 'previous-week']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        /** @var array{imported: list<array{xp: array{awarded: int}, attributes: array<string, array<string, int>>}>} $body */
        $body = self::decode($response);
        self::assertCount(1, $body['imported']);
        self::assertGreaterThan(0, $body['imported'][0]['xp']['awarded']);
        foreach ($body['imported'][0]['attributes'] as $attribute => $gauge) {
            self::assertSame(0, $gauge['before']);
            self::assertSame(0, $gauge['after']);
            if ('vitality' !== $attribute) {
                self::assertSame(0, $gauge['gained']);
            }
        }
    }

    public function testResetExpiresOfflineSnapshotsAndLateCreditsKeepTheirSportingWeek(): void
    {
        $player = $this->openAccount()->id;
        $container = self::getContainer();
        $clock = new MockClock('2026-10-25 19:59:59 Europe/Paris');
        $registry = $container->get(ManagerRegistry::class);
        $rules = $container->get(GameRulesets::class);
        $ledger = new XpTransactionRepository($registry, $clock, $rules);
        $snapshots = new ProgressionSnapshotRepository($registry, $clock, $rules);
        $curve = $container->get(LevelCurve::class);
        $vitality = $container->get(Vitality::class);
        $em = $container->get(EntityManagerInterface::class);

        $credit = self::credit($player, '2026-10-24 12:00:00 Europe/Paris');
        $ledger->add($credit);
        $ledger->commit();
        $snapshot = ProgressionSnapshot::untouched($player, $curve, $vitality, $clock->now());
        $snapshot->retotal(400, $ledger->attributeTotalsOf($player), $curve, $vitality, $clock->now());
        $em->persist($snapshot);
        $em->flush();
        self::assertSame(400, $snapshots->characteristicsOf($snapshot)->attributes->total());

        $clock->sleep(1);
        self::assertSame(0, $ledger->attributeTotalsOf($player)->total());
        self::assertSame(0, $snapshots->characteristicsOf($snapshot)->attributes->total());
        self::assertSame(0, $snapshots->characteristicsOf($snapshot)->vitality);
        self::assertSame(0, $snapshots->progressionsOf([$player])[$player->toRfc4122()]->characteristics->attributes->total());
        self::assertSame(400, $snapshot->totalXp());

        // Un import tardif et son annulation restent dans leur semaine sportive.
        $ledger->add(self::credit($player, '2026-10-24 14:00:00 Europe/Paris'));
        $ledger->add(XpTransaction::reversalOf($credit));
        $ledger->commit();
        self::assertSame(0, $ledger->attributeTotalsOf($player)->total());
        $ledger->add(self::credit($player, '2026-10-25 20:00:00 Europe/Paris'));
        $ledger->commit();
        self::assertSame(400, $ledger->attributeTotalsOf($player)->total());
        self::assertSame(800, $ledger->totalOf($player));

        // Le reset suivant est une semaine civile : il traverse ici le changement DST.
        $clock->modify('2026-11-01 20:00:00 Europe/Paris');
        self::assertSame(0, $ledger->attributeTotalsOf($player)->total());
    }

    private static function credit(Uuid $player, string $at): XpTransaction
    {
        return XpTransaction::creditFor($player, Uuid::v7(), Discipline::Running, 3600, new XpAward(new XpBreakdown(new XpBreakdownLine(XpBreakdownSource::Base, 400)), new AttributeGains(100, 100, 100, 100), 'weekly-test'), new DateTimeImmutable($at));
    }
}
