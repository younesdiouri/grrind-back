<?php

declare(strict_types=1);

namespace App\Tests\Community;

use App\Community\Domain\GuildWeek;
use App\Progression\Domain\XpAward;
use App\Progression\Domain\XpBreakdown;
use App\Progression\Domain\XpBreakdownLine;
use App\Progression\Domain\XpBreakdownSource;
use App\Progression\Domain\XpTransaction;
use App\Shared\Application\WorkoutCounts;
use App\Shared\Domain\Activity\AttributeGains;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Activity\WorkoutSource;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use App\Training\Domain\Workout;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\DataCollector\DoctrineDataCollector;
use Symfony\Component\HttpKernel\Profiler\Profile;

final class GuildWeeklyLeaderboardTest extends ApiTestCase
{
    private const string PATH = '/api/guilds/mine/weekly-leaderboard';

    public function testAnAnonymousCallerIsRefused(): void
    {
        self::assertSame(401, $this->get(self::PATH)->getStatusCode());
    }

    public function testAPlayerWithoutAGuildGetsGuildNotFound(): void
    {
        $response = $this->get(self::PATH, $this->openAccount()->headers);
        self::assertSame(404, $response->getStatusCode());
        self::assertIsString($type = self::decode($response)['type']);
        self::assertStringEndsWith('/guild-not-found', $type);
    }

    public function testAnInactiveGuildKeepsItsMembersWithoutAwardingARank(): void
    {
        $founder = $this->openAccount();
        $created = $this->post('/api/guilds', ['name' => 'Les Réguliers'], $founder->headers);
        self::assertSame(201, $created->getStatusCode());
        $guildId = self::decode($created)['id'];

        $response = $this->get(self::PATH, $founder->headers);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $body = self::decode($response);
        self::assertSame($guildId, $body['guildId']);
        self::assertSame('Europe/Paris', $body['timezone']);
        self::assertSame(0, $body['totalWorkouts']);
        self::assertSame(0, $body['activeMemberCount']);
        self::assertIsArray($entries = $body['entries']);
        self::assertCount(1, $entries);
        self::assertIsArray($entry = $entries[0]);
        self::assertSame(0, $entry['workoutCount']);
        self::assertNull($entry['rank']);
        self::assertIsArray($entry['member']);
        self::assertSame($founder->id->toRfc4122(), $entry['member']['id']);
        self::assertSame('FOUNDER', $entry['member']['role']);
    }

    public function testCompetitionRanksTiesAndTotalsUseWorkoutsRatherThanXp(): void
    {
        $founder = $this->openAccount();
        $guildId = $this->foundGuild($founder);
        $anna = $this->join($founder, $guildId, 'Anna');
        $ben = $this->join($founder, $guildId, 'Ben');
        $idle = $this->join($founder, $guildId, 'Idle');
        $outsider = $this->openAccount('outsider@example.com');
        $start = GuildWeek::containing(new DateTimeImmutable())->startsAt;
        foreach ([$founder, $anna, $ben, $idle] as $index => $member) {
            $this->joinedAt($member, $start->modify('-1 day')->modify('+'.$index.' minutes'));
        }
        foreach ([$founder, $anna] as $member) {
            for ($i = 1; $i <= 3; ++$i) {
                $this->record($member, $start->modify('+'.$i.' hours'), xp: 0);
            }
        }
        $this->record($ben, $start->modify('+5 hours'), xp: 10000);
        $this->record($outsider, $start->modify('+5 hours'));

        $body = $this->board($founder);
        self::assertSame(7, $body['totalWorkouts']);
        self::assertSame(3, $body['activeMemberCount']);
        $entries = self::entries($body);
        self::assertSame([3, 3, 1, 0], array_column($entries, 'workoutCount'));
        self::assertSame([1, 1, 3, null], array_column($entries, 'rank'));
        self::assertSame(['Bob', 'Anna', 'Ben', 'Idle'], array_column(array_column($entries, 'member'), 'displayName'));
        self::assertSame($body, $this->board($anna), 'Le classement ne dépend pas du membre qui le consulte.');
        self::assertSame($body, $this->board($founder), 'Les ex æquo gardent leur ordre d\'affichage.');

        $guild = self::decode($this->get('/api/guilds/mine', $founder->headers))['guild'];
        self::assertIsArray($guild);
        self::assertIsArray($guild['members']);
        self::assertEquals($guild['members'][0], $entries[0]['member'], 'Réutiliser exactement GuildMember.');
    }

    public function testEndedAtUsesInclusiveOpeningAndExclusiveClosingRegardlessOfImportDate(): void
    {
        $player = $this->openAccount();
        $this->foundGuild($player);
        $week = GuildWeek::containing(new DateTimeImmutable());
        $this->joinedAt($player, $week->startsAt->modify('-1 week'));
        foreach ([$week->startsAt->modify('-1 second'), $week->startsAt, $week->startsAt->modify('+1 second'), $week->endsAt->modify('-1 second'), $week->endsAt, $week->endsAt->modify('+1 second')] as $endedAt) {
            // Même date d'import pour toutes : elle tombe hors de la semaine sportive.
            $this->record($player, $endedAt, importedAt: $week->endsAt->modify('+1 day'));
        }

        $body = $this->board($player);
        self::assertSame($week->startsAt->format('c'), $body['weekStartsAt']);
        self::assertSame($week->endsAt->format('c'), $body['weekEndsAt']);
        self::assertSame(3, $body['totalWorkouts']);
        self::assertSame(3, self::entries($body)[0]['workoutCount']);
    }

    public function testOnlyWorkoutsEndedSinceTheCurrentMembershipCount(): void
    {
        $player = $this->openAccount();
        $this->foundGuild($player);
        $joined = GuildWeek::containing(new DateTimeImmutable())->startsAt->modify('+1 day');
        $this->joinedAt($player, $joined);
        $this->record($player, $joined->modify('-1 second'));
        $this->record($player, $joined);
        $this->record($player, $joined->modify('+1 second'));

        self::assertSame(2, $this->board($player)['totalWorkouts']);
    }

    public function testDepartureAndRejoiningDoNotCarryOldWorkouts(): void
    {
        $founder = $this->openAccount();
        $guildId = $this->foundGuild($founder);
        $member = $this->join($founder, $guildId, 'Anna');
        $start = GuildWeek::containing(new DateTimeImmutable())->startsAt;
        $this->joinedAt($member, $start->modify('-1 day'));
        $this->record($member, $start);
        self::assertSame(1, $this->board($founder)['totalWorkouts']);

        self::assertSame(204, $this->post('/api/guilds/mine/leave', [], $member->headers)->getStatusCode());
        $remaining = $this->board($founder);
        self::assertCount(1, self::entries($remaining));
        self::assertSame(0, $remaining['totalWorkouts']);

        $this->joinExisting($founder, $guildId, $member);
        $rejoined = $this->board($founder);
        self::assertCount(2, self::entries($rejoined));
        self::assertSame(0, $rejoined['totalWorkouts']);
        self::assertSame([null, null], array_column(self::entries($rejoined), 'rank'));

        self::assertSame(204, $this->post('/api/guilds/mine/leave', [], $member->headers)->getStatusCode());
        $otherId = $this->foundGuild($member);
        self::assertNotSame($guildId, $otherId);
        self::assertSame(0, $this->board($member)['totalWorkouts'], 'Changer de guilde ne transporte pas les séances précédentes.');
    }

    public function testInvalidationRemovesACreditedWorkoutEvenWhenItAwardedZeroXp(): void
    {
        $player = $this->openAccount();
        $this->foundGuild($player);
        $start = GuildWeek::containing(new DateTimeImmutable())->startsAt;
        $this->joinedAt($player, $start->modify('-1 day'));
        $credit = $this->record($player, $start->modify('+3 hours'), xp: 0);
        self::assertInstanceOf(XpTransaction::class, $credit);
        $this->record($player, $start->modify('+5 hours'), credited: false);
        self::assertSame(1, $this->board($player)['totalWorkouts']);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist(XpTransaction::reversalOf($credit));
        $em->flush();
        $body = $this->board($player);
        self::assertSame(0, $body['totalWorkouts']);
        self::assertNull(self::entries($body)[0]['rank']);
    }

    public function testLateImportsIgnoredWorkoutsAndRetriesKeepOneCredit(): void
    {
        $player = $this->openAccount();
        $this->foundGuild($player);
        // Toujours dans le passé, y compris si le test tourne un lundi matin.
        $start = new DateTimeImmutable('tuesday last week 08:00 Europe/Paris');
        $week = GuildWeek::containing($start);
        $this->joinedAt($player, $week->startsAt);
        $workout = static fn (string $id, string $type, DateTimeImmutable $from, int $minutes = 30): array => [
            'externalId' => $id, 'activityType' => $type, 'source' => 'APPLE_HEALTH',
            'startedAt' => $from->format('c'), 'endedAt' => $from->modify('+'.$minutes.' minutes')->format('c'),
        ];
        $payload = ['workouts' => [
            $workout('valid', 'running', $start),
            $workout('valid', 'running', $start),
            $workout('walking', 'walking', $start->modify('+1 hour')),
            $workout('short', 'running', $start->modify('+2 hours'), 1),
            $workout('unknown', 'not-a-sport', $start->modify('+3 hours')),
            $workout('overlap', 'running', $start->modify('+10 minutes'), 5),
            $workout('too-old', 'running', $start->modify('-40 days')),
            $workout('alam-window', 'running', $start->modify('sunday this week')->setTime(19, 0)),
        ]];
        $headers = $player->headers + ['Idempotency-Key' => 'weekly-late'];
        $first = $this->post('/api/workouts/import', $payload, $headers);
        self::assertSame(200, $first->getStatusCode(), (string) $first->getContent());
        $replay = $this->post('/api/workouts/import', $payload, $headers);
        self::assertSame($first->getContent(), $replay->getContent());
        self::assertSame(200, $this->post('/api/workouts/import', $payload, $player->headers + ['Idempotency-Key' => 'weekly-new-key'])->getStatusCode());

        $counts = self::getContainer()->get(WorkoutCounts::class);
        self::assertSame([$player->id->toRfc4122() => 1], $counts->since([$player->id->toRfc4122() => $week->startsAt], $week->endsAt));
        self::assertSame(1, self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM xp_transaction'), 'Les séances ignorées, la marche et la fenêtre Alam ne créent aucun crédit.');
    }

    public function testQueryCountDoesNotGrowWithTheGuild(): void
    {
        $founder = $this->openAccount();
        $guildId = $this->foundGuild($founder);
        $start = GuildWeek::containing(new DateTimeImmutable())->startsAt;
        $this->joinedAt($founder, $start->modify('-1 day'));
        $this->record($founder, $start->modify('+1 hour'));
        $small = $this->queryCount($founder);
        foreach (['Anna', 'Ben', 'Carla', 'Dan'] as $name) {
            $member = $this->join($founder, $guildId, $name);
            $this->joinedAt($member, $start->modify('-1 day'));
            $this->record($member, $start->modify('+1 hour'));
        }
        self::assertGreaterThan(0, $small);
        self::assertSame($small, $this->queryCount($founder));
    }

    /** @return array<mixed> */
    private function board(Account $player): array
    {
        $response = $this->get(self::PATH, $player->headers);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return self::decode($response);
    }

    /** @param array<mixed> $body
     * @return list<array<string, mixed>>
     */
    private static function entries(array $body): array
    {
        self::assertIsArray($body['entries']);
        /** @var list<array<string, mixed>> $entries */
        $entries = $body['entries'];

        return $entries;
    }

    private function foundGuild(Account $founder): string
    {
        $response = $this->post('/api/guilds', ['name' => 'Les Réguliers'], $founder->headers);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertIsString($id = self::decode($response)['id']);

        return $id;
    }

    private function join(Account $founder, string $guildId, string $name): Account
    {
        $member = $this->openAccount(strtolower($name).'@example.com', $name);
        $this->joinExisting($founder, $guildId, $member);

        return $member;
    }

    private function joinExisting(Account $founder, string $guildId, Account $member): void
    {
        $invite = $this->post('/api/guilds/'.$guildId.'/invite-code', [], $founder->headers);
        self::assertSame(201, $invite->getStatusCode(), (string) $invite->getContent());
        self::assertSame(200, $this->post('/api/guilds/join', ['code' => self::decode($invite)['code']], $member->headers)->getStatusCode());
    }

    private function joinedAt(Account $member, DateTimeImmutable $joinedAt): void
    {
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE community_guild_membership SET joined_at = ? WHERE player_id = ?', [$joinedAt->format('c'), $member->id->toRfc4122()]);
    }

    /** Des faits archivés explicites pour tester les bornes sans contourner l'horloge d'import. */
    private function record(Account $player, DateTimeImmutable $endedAt, bool $credited = true, int $xp = 1, ?DateTimeImmutable $importedAt = null): ?XpTransaction
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $workout = Workout::record($player->id, Discipline::Running, WorkoutSource::AppleHealth, $endedAt->modify('-30 minutes'), $endedAt, $importedAt ?? new DateTimeImmutable());
        $em->persist($workout);
        $credit = null;
        if ($credited) {
            $award = new XpAward(new XpBreakdown(new XpBreakdownLine(XpBreakdownSource::Base, $xp)), new AttributeGains(0, $xp, 0, 0), 'weekly-test');
            $credit = XpTransaction::creditFor($player->id, $workout->id(), Discipline::Running, 1800, $award, $workout->startedAt());
            $em->persist($credit);
        }
        $em->flush();

        return $credit;
    }

    private function queryCount(Account $player): int
    {
        $this->client->enableProfiler();
        $this->board($player);
        $profile = $this->client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        return $collector->getQueryCount();
    }
}
