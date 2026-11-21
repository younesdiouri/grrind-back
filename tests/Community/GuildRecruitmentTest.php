<?php

declare(strict_types=1);

namespace App\Tests\Community;

use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;

final class GuildRecruitmentTest extends ApiTestCase
{
    private function found(Account $owner, bool $open = true): string
    {
        $guild = self::decode($this->post('/api/guilds', ['name' => 'Guilde'], $owner->headers));
        self::assertFalse($guild['recruitmentOpen']);
        self::assertIsString($guild['id']);
        $uri = '/api/guilds/'.$guild['id'];
        if ($open) {
            self::assertSame(200, $this->send('PATCH', $uri, ['recruitmentOpen' => true], $owner->headers)->getStatusCode());
        }

        return $uri;
    }

    private function apply(string $uri, Account $player, ?string $key = null): string
    {
        $response = $this->post($uri.'/applications', [], $player->headers + ['Idempotency-Key' => $key ?? Uuid::v7()->toRfc4122()]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $application = self::decode($response);
        self::assertSame('PENDING', $application['status']);
        self::assertIsString($application['id']);

        return $application['id'];
    }

    public function testDirectoryPreviewPrivacyAndPagination(): void
    {
        $owner = $this->openAccount();
        $other = $this->openAccount('other@example.org', 'Autre');
        $player = $this->openAccount('player@example.org', 'Joueur');
        $first = $this->found($owner, false);
        $second = $this->found($other);
        $dir = '/api/guilds/directory';
        self::assertSame(401, $this->get($dir)->getStatusCode());
        self::assertSame(404, $this->get($first.'/preview', $player->headers)->getStatusCode());
        self::assertFalse(self::decode($this->get($first.'/preview', $owner->headers))['recruitmentOpen']);
        $preview = self::decode($this->get($second.'/preview', $player->headers));
        self::assertEqualsCanonicalizing(['id', 'name', 'memberCount', 'emblem', 'capacity', 'recruitmentOpen'], array_keys($preview));
        self::assertSame(1, $preview['memberCount']);
        self::assertSame(['items' => [$preview], 'nextCursor' => null], self::decode($this->get($dir, $player->headers)));
        self::assertSame(404, $this->get($second, $player->headers)->getStatusCode());
        self::assertSame(404, $this->get($second.'/applications', $player->headers)->getStatusCode());
        self::assertSame(422, $this->get($dir.'?limit=0', $player->headers)->getStatusCode());
        self::assertSame(422, $this->get($dir.'?cursor=bad', $player->headers)->getStatusCode());
        self::assertSame(200, $this->send('PATCH', $first, ['recruitmentOpen' => true], $owner->headers)->getStatusCode());
        $page = self::decode($this->get($dir.'?limit=1', $player->headers));
        self::assertSame([$preview], $page['items']);
        self::assertSame($preview['id'], $page['nextCursor']);
        self::assertIsString($page['nextCursor']);
        $last = self::decode($this->get($dir.'?limit=1&cursor='.$page['nextCursor'], $player->headers));
        self::assertNull($last['nextCursor']);
        self::assertSame([self::decode($this->get($first.'/preview', $player->headers))], $last['items']);
    }

    public function testDecisionsRetriesAndPermissions(): void
    {
        $owner = $this->openAccount();
        $player = $this->openAccount('player@example.org', 'Joueur');
        $other = $this->openAccount('other@example.org', 'Autre');
        $uri = $this->found($owner);
        $second = $this->found($other);
        $this->apply($second, $player);
        $id = $this->apply($uri, $player, 'first');
        self::assertSame($id, $this->apply($uri, $player));
        $decision = $uri.'/applications/'.$id;
        self::assertSame(404, $this->post($decision.'/accept', [], $player->headers)->getStatusCode());
        self::assertSame(404, $this->post($second.'/applications/'.$id.'/accept', [], $other->headers)->getStatusCode());
        foreach (['reject', 'reject'] as $action) {
            self::assertSame('REJECTED', self::decode($this->post($decision.'/'.$action, [], $owner->headers))['status']);
        }
        self::assertSame($id, $this->apply($uri, $player, 'first'));
        self::assertSame(409, $this->post($decision.'/accept', [], $owner->headers)->getStatusCode());
        $new = $this->apply($uri, $player);
        self::assertNotSame($id, $new);
        foreach (['accept', 'accept'] as $action) {
            self::assertSame('ACCEPTED', self::decode($this->post($uri.'/applications/'.$new.'/'.$action, [], $owner->headers))['status']);
        }
        self::assertSame(403, $this->get($uri.'/applications', $player->headers)->getStatusCode());
        self::assertSame(403, $this->post($decision.'/reject', [], $player->headers)->getStatusCode());
        self::assertSame(2, self::decode($this->get($uri, $player->headers))['memberCount']);
        $history = self::decode($this->get('/api/guild-applications/mine', $player->headers))['items'];
        self::assertIsArray($history);
        self::assertSame(['ACCEPTED', 'REJECTED', 'WITHDRAWN'], array_column($history, 'status'));
        self::assertSame([], self::decode($this->get($second.'/applications', $other->headers))['items']);
        self::assertSame([], self::decode($this->get('/api/guild-applications/mine', $owner->headers))['items']);
    }

    /** @return iterable<string, array{string}> */
    public static function withdrawals(): iterable
    {
        foreach (['close', 'dissolve', 'leave', 'found', 'code'] as $cause) {
            yield $cause => [$cause];
        }
    }

    #[DataProvider('withdrawals')]
    public function testEndingRecruitmentOrJoiningElsewhereWithdrawsTheApplication(string $cause): void
    {
        $owner = $this->openAccount();
        $player = $this->openAccount('player@example.org', 'Joueur');
        $uri = $this->found($owner);
        $id = $this->apply($uri, $player);
        if ('close' === $cause) {
            self::assertSame(200, $this->send('PATCH', $uri, ['recruitmentOpen' => false], $owner->headers)->getStatusCode());
            self::assertSame(404, $this->get($uri.'/preview', $player->headers)->getStatusCode());
            self::assertSame(409, $this->post($uri.'/applications/'.$id.'/accept', [], $owner->headers)->getStatusCode());
            self::assertSame(200, $this->send('PATCH', $uri, ['recruitmentOpen' => true], $owner->headers)->getStatusCode());
            self::assertNotSame($id, $this->apply($uri, $player));
        } elseif ('dissolve' === $cause) {
            self::assertSame(204, $this->send('DELETE', $uri, null, $owner->headers)->getStatusCode());
        } elseif ('leave' === $cause) {
            self::assertSame(204, $this->post('/api/guilds/mine/leave', [], $owner->headers)->getStatusCode());
        } elseif ('found' === $cause) {
            $this->found($player);
        } else {
            $code = self::decode($this->post($uri.'/invite-code', [], $owner->headers))['code'];
            self::assertSame(200, $this->post('/api/guilds/join', ['code' => $code], $player->headers)->getStatusCode());
        }

        $history = self::decode($this->get('/api/guild-applications/mine', $player->headers))['items'];
        self::assertIsArray($history);
        self::assertSame('WITHDRAWN', array_column($history, 'status', 'id')[$id]);
    }
}
