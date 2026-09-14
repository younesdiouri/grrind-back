<?php

declare(strict_types=1);

namespace App\Tests\Community;

use App\Progression\Domain\XpTransaction;
use App\Shared\Application\AlamContributions;
use App\Shared\Application\FrozenGameRulesets;
use App\Shared\Application\GameRulesets;
use App\Shared\Domain\Modifier\Modifier;
use App\Shared\Domain\Modifier\ModifierSource;
use App\Shared\Domain\Modifier\ModifierType;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\ProgrammableModifiers;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/** @phpstan-import-type Params from \Doctrine\DBAL\DriverManager */
final class AlamContributionsTest extends ApiTestCase
{
    public function testBonusesAreIgnoredAndInvalidatedSourcesDisappear(): void
    {
        $plain = $this->openAccount();
        $boosted = $this->openAccount('boosted@example.com');
        $start = new DateTimeImmutable('-2 days 07:00:00');
        $payload = ['workouts' => [['externalId' => 'same-effort', 'activityType' => 'running', 'source' => 'APPLE_HEALTH', 'startedAt' => $start->format('c'), 'endedAt' => $start->modify('+60 minutes')->format('c'), 'distanceMeters' => 10000]]];
        self::assertSame(200, $this->post('/api/workouts/import', $payload, $plain->headers + ['Idempotency-Key' => 'plain'])->getStatusCode());
        ProgrammableModifiers::grant(new Modifier(ModifierType::XpMultiplier, 100, ModifierSource::Item));
        self::assertSame(200, $this->post('/api/workouts/import', $payload, $boosted->headers + ['Idempotency-Key' => 'boosted'])->getStatusCode());
        $rulesets = self::getContainer()->get(GameRulesets::class);
        $rules = new FrozenGameRulesets($rulesets->snapshot(), $rulesets->version());
        $contributions = self::getContainer()->get(AlamContributions::class);
        $end = $start->modify('+1 day');
        $gains = $contributions->between([$plain->id, $boosted->id], $start, $end, $rules);
        self::assertSame($gains[$plain->id->toRfc4122()], $gains[$boosted->id->toRfc4122()]);
        self::assertGreaterThan(0, $gains[$plain->id->toRfc4122()]['total']);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $credit = $em->getRepository(XpTransaction::class)->findOneBy(['userId' => $plain->id]);
        self::assertInstanceOf(XpTransaction::class, $credit);
        $em->persist(XpTransaction::reversalOf($credit));
        $em->flush();
        $gains = $contributions->between([$plain->id, $boosted->id], $start, $end, $rules);
        self::assertSame(0, $gains[$plain->id->toRfc4122()]['total']);
        self::assertSame(1, $contributions->activeCount([$plain->id, $boosted->id], $start, $end));
        self::assertSame(1, $contributions->activeCount([], $start, $end));
    }

    public function testResolutionLocksTheSameProgressionRowsAsConcurrentImports(): void
    {
        $player = $this->openAccount();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $rules = self::getContainer()->get(GameRulesets::class);
        $contributions = self::getContainer()->get(AlamContributions::class);
        $em->wrapInTransaction(static fn () => $contributions->lock([$player->id], $rules));
        /** @var Params $params */
        $params = $em->getConnection()->getParams();
        $other = \Doctrine\DBAL\DriverManager::getConnection($params);
        $em->beginTransaction();
        try {
            $contributions->lock([$player->id], $rules);
            $other->executeStatement("SET lock_timeout = '100ms'");
            try {
                $other->fetchOne('SELECT user_id FROM progression_snapshot WHERE user_id = ? FOR UPDATE', [$player->id->toRfc4122()]);
                self::fail('Un import concurrent ne doit pas traverser le figement.');
            } catch (\Doctrine\DBAL\Exception\DriverException $exception) {
                self::assertSame('55P03', $exception->getSQLState());
            }
        } finally {
            $em->rollback();
            $other->close();
        }
    }

    public function testSundayWindowPreservesWorkoutButExcludesXpAndLoot(): void
    {
        $player = $this->openAccount();
        $start = new DateTimeImmutable('last sunday 19:00 Europe/Paris');
        $payload = ['workouts' => [['externalId' => 'excluded', 'activityType' => 'running', 'source' => 'APPLE_HEALTH', 'startedAt' => $start->format('c'), 'endedAt' => $start->modify('+60 minutes')->format('c'), 'distanceMeters' => 10000]]];
        $response = $this->post('/api/workouts/import', $payload, $player->headers + ['Idempotency-Key' => 'excluded']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(1, $em->getConnection()->fetchOne('SELECT COUNT(*) FROM workout'));
        self::assertSame(0, $em->getConnection()->fetchOne('SELECT COUNT(*) FROM xp_transaction'));
        self::assertSame(0, $em->getConnection()->fetchOne('SELECT COUNT(*) FROM rewards_loot_roll'));
        $body = self::decode($response);
        self::assertIsArray($body['imported']);
        self::assertIsArray($body['imported'][0]);
        self::assertIsArray($body['imported'][0]['xp']);
        self::assertSame('ALAM_WINDOW', $body['imported'][0]['xp']['reason']);
    }
}
