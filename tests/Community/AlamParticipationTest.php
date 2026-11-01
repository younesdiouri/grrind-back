<?php

declare(strict_types=1);

namespace App\Tests\Community;

use App\Community\Application\AlamRuns;
use App\Shared\Application\GameRulesets;
use App\Shared\Domain\Alam\AlamCalendar;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Response;

/**
 * @phpstan-type Raid array{id: string, status: string, collectionEndsAt: string, difficulty: ?string, participants: list<array{playerId: string}>, difficultyOptions: list<array{thresholdPermille: int, probabilityMillionths: int}>, encounters: list<array{thresholdPermille: int}>}
 * @phpstan-type AlamState array{registered: bool, canChooseDifficulty: bool, current: Raid}
 */
final class AlamParticipationTest extends ApiTestCase
{
    private MockClock $clock;

    protected function setUp(): void
    {
        $_ENV['ALAM_MANUAL_ENABLED'] = $_SERVER['ALAM_MANUAL_ENABLED'] = '1';
        parent::setUp();
        $this->client->disableReboot();
        // @phpstan-ignore symfonyContainer.serviceNotFound (Service réservé au conteneur test.)
        $clock = self::getContainer()->get('test.alam.clock');
        self::assertInstanceOf(MockClock::class, $clock);
        $this->clock = $clock;
        $week = AlamCalendar::runtime(self::getContainer()->get(GameRulesets::class))->week(new DateTimeImmutable());
        $this->clock->modify($week['start']->modify('+1 day')->format('c'));
    }

    protected function tearDown(): void
    {
        $_ENV['ALAM_MANUAL_ENABLED'] = $_SERVER['ALAM_MANUAL_ENABLED'] = '0';
        parent::tearDown();
    }

    public function testPersonalOptInAndFounderChoiceAreValidatedAndIdempotent(): void
    {
        $chief = $this->openAccount();
        $guild = self::decode($this->post('/api/guilds', ['name' => 'Raid hebdomadaire'], $chief->headers));
        self::assertIsString($guild['id']);
        $initial = self::state($this->get('/api/guild/alam', $chief->headers));
        self::assertFalse($initial['registered']);
        self::assertTrue($initial['canChooseDifficulty']);
        self::assertSame([], $initial['current']['participants']);
        self::assertSame('EASY', $initial['current']['difficulty']);
        self::assertSame([250, 550, 1000], array_column($initial['current']['difficultyOptions'], 'thresholdPermille'));
        self::assertSame([0, 0, 0], array_column($initial['current']['difficultyOptions'], 'probabilityMillionths'));

        $registered = $this->registration($chief, true, 'register-chief');
        self::assertTrue($registered['registered']);
        self::assertCount(1, $registered['current']['participants']);
        self::assertSame($chief->id->toRfc4122(), $registered['current']['participants'][0]['playerId']);
        $replay = self::state($this->send('PUT', '/api/guild/alam/registration', ['registered' => true], $chief->headers + ['Idempotency-Key' => 'register-chief']));
        self::assertSame($registered, $replay);

        $choice = $this->send('PUT', '/api/guild/alam/difficulty', ['difficulty' => 'HARD'], $chief->headers + ['Idempotency-Key' => 'hard']);
        self::assertSame(200, $choice->getStatusCode(), (string) $choice->getContent());
        self::assertSame('HARD', self::state($choice)['current']['difficulty']);

        $code = self::decode($this->post('/api/guilds/'.$guild['id'].'/invite-code', [], $chief->headers))['code'];
        self::assertIsString($code);
        $member = $this->openAccount('member@grrind.app');
        self::assertSame(200, $this->post('/api/guilds/join', ['code' => $code], $member->headers)->getStatusCode());
        self::assertSame(403, $this->send('PUT', '/api/guild/alam/difficulty', ['difficulty' => 'EASY'], $member->headers + ['Idempotency-Key' => 'member-choice'])->getStatusCode());
        $both = $this->registration($member, true, 'register-member');
        self::assertFalse($both['canChooseDifficulty']);
        self::assertCount(2, $both['current']['participants']);
        $onlyMember = $this->registration($chief, false, 'unregister-chief');
        self::assertCount(1, $onlyMember['current']['participants']);
        self::assertSame($member->id->toRfc4122(), $onlyMember['current']['participants'][0]['playerId']);

        self::assertSame(422, $this->send('PUT', '/api/guild/alam/registration', [], $chief->headers + ['Idempotency-Key' => 'empty-registration'])->getStatusCode());
        self::assertSame(422, $this->send('PUT', '/api/guild/alam/difficulty', ['difficulty' => 'IMPOSSIBLE'], $chief->headers + ['Idempotency-Key' => 'bad-difficulty'])->getStatusCode());
        $outsider = $this->openAccount('outsider@grrind.app');
        self::assertSame(404, $this->send('PUT', '/api/guild/alam/registration', ['registered' => true], $outsider->headers + ['Idempotency-Key' => 'outsider'])->getStatusCode());
    }

    public function testDeadlineFreezesASingleFightAndOnlyDifficultyResetsNextWeek(): void
    {
        $chief = $this->openAccount();
        $this->post('/api/guilds', ['name' => 'Une seule rencontre'], $chief->headers);
        $registered = $this->registration($chief, true, 'register');
        $this->send('PUT', '/api/guild/alam/difficulty', ['difficulty' => 'INTERMEDIATE'], $chief->headers + ['Idempotency-Key' => 'intermediate']);
        $this->clock->modify($registered['current']['collectionEndsAt']);
        $service = self::getContainer()->get(AlamRuns::class);
        $service->tick();
        $resolved = self::state($this->get('/api/guild/alam', $chief->headers));
        self::assertSame('RESOLVED', $resolved['current']['status']);
        self::assertCount(1, $resolved['current']['encounters']);
        self::assertSame(550, $resolved['current']['encounters'][0]['thresholdPermille']);
        self::assertFalse($resolved['canChooseDifficulty']);
        self::assertSame(409, $this->send('PUT', '/api/guild/alam/difficulty', ['difficulty' => 'HARD'], $chief->headers + ['Idempotency-Key' => 'too-late'])->getStatusCode());

        $this->clock->modify('+1 hour');
        $next = self::state($this->get('/api/guild/alam', $chief->headers));
        self::assertTrue($next['registered']);
        self::assertSame('EASY', $next['current']['difficulty']);
        self::assertSame('COLLECTING', $next['current']['status']);
        self::assertNotSame($resolved['current']['id'], $next['current']['id']);
        $history = self::decode($this->get('/api/guild/alam/runs/'.$resolved['current']['id'], $chief->headers));
        self::assertSame($resolved['current']['encounters'], $history['encounters']);
        self::assertSame($resolved['current']['difficultyOptions'], $history['difficultyOptions']);

        $this->post('/api/guilds/mine/leave', [], $chief->headers);
        $this->post('/api/guilds', ['name' => 'Nouvelle guilde'], $chief->headers);
        self::assertFalse(self::state($this->get('/api/guild/alam', $chief->headers))['registered']);
    }

    /** @return AlamState */
    private function registration(Account $player, bool $registered, string $key): array
    {
        $response = $this->send('PUT', '/api/guild/alam/registration', ['registered' => $registered], $player->headers + ['Idempotency-Key' => $key]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return self::state($response);
    }

    /** @return AlamState */
    private static function state(Response $response): array
    {
        /** @var AlamState $state */
        $state = self::decode($response);

        return $state;
    }
}
