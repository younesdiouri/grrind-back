<?php

declare(strict_types=1);

namespace App\Tests\Community;

use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\Uid\Uuid;

final class GuildChatTest extends ApiTestCase
{
    public function testTextHistoryCatchupAndIdempotence(): void
    {
        [$author, $url] = $this->chat();
        $payload = ['clientId' => Uuid::v7()->toRfc4122(), 'text' => 'Bonjour'];
        $response = $this->post($url, $payload, $author->headers);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $first = self::decode($response);
        self::assertSame($first, self::decode($this->post($url, $payload, $author->headers)));
        self::assertSame(409, $this->post($url, [...$payload, 'text' => 'Autre'], $author->headers)->getStatusCode());
        $this->post($url, ['clientId' => Uuid::v7()->toRfc4122(), 'text' => 'Deuxième'], $author->headers);
        $page = self::decode($this->get($url.'?limit=1', $author->headers));
        self::assertIsArray($page['messages']);
        self::assertCount(1, $page['messages']);
        self::assertIsString($page['nextCursor']);
        $older = self::decode($this->get($url.'?before='.$page['nextCursor'], $author->headers));
        self::assertSame([$first], $older['messages']);
        self::assertIsString($first['cursor']);
        $catchup = self::decode($this->get($url.'?after='.$first['cursor'], $author->headers));
        self::assertIsArray($catchup['messages']);
        self::assertCount(1, $catchup['messages']);
        self::assertSame(404, $this->get($url, $this->openAccount('outsider@grrind.app')->headers)->getStatusCode());
    }

    public function testRejectsEmptyInvalidAndOversizedMessages(): void
    {
        [$author, $url] = $this->chat();
        foreach ([['clientId' => 'bad', 'text' => 'x'], ['clientId' => Uuid::v7()->toRfc4122()], ['clientId' => Uuid::v7()->toRfc4122(), 'text' => str_repeat('a', 4001)]] as $payload) {
            self::assertSame(422, $this->post($url, $payload, $author->headers)->getStatusCode());
        }
    }

    /** @return array{Account, string} */
    private function chat(): array
    {
        $author = $this->openAccount();
        $guild = self::decode($this->post('/api/guilds', ['name' => 'Chat'], $author->headers));
        self::assertIsString($guild['id']);

        return [$author, '/api/guilds/'.$guild['id'].'/chat/messages'];
    }
}
