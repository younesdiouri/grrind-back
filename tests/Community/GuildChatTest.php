<?php

declare(strict_types=1);

namespace App\Tests\Community;

use App\Community\Application\ChatChanged;
use App\Community\Application\ChatChangedHandler;
use App\Community\Application\PreparedChatImage;
use App\Community\Application\SendGuildMessage;
use App\Community\Infrastructure\Storage\CleanupChatImages;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
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

    public function testPrivateImageIsReencodedAndReplayKeepsTheSameMessage(): void
    {
        [$author, $url] = $this->chat();
        $clientId = Uuid::v7()->toRfc4122();
        $response = $this->upload($url, $author, $clientId);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $message = self::decode($response);
        self::assertSame('', $message['text']);
        self::assertIsString($message['imageUrl']);
        self::assertSame($message, self::decode($this->upload($url, $author, $clientId)));
        $image = $this->get($message['imageUrl'], $author->headers);
        self::assertSame(200, $image->getStatusCode());
        self::assertSame('image/webp', $image->headers->get('Content-Type'));
        self::assertTrue($image->headers->hasCacheControlDirective('no-store'));
        self::assertSame(404, $this->get($message['imageUrl'], $this->openAccount('other@grrind.app')->headers)->getStatusCode());
        $this->send('POST', '/api/guilds/mine/leave', null, $author->headers);
        self::assertSame(404, $this->get($message['imageUrl'], $author->headers)->getStatusCode());
    }

    public function testDepartureAndExclusionRemoveAllChatAccess(): void
    {
        [$author, $url] = $this->chat();
        $base = substr($url, 0, -\strlen('/chat/messages'));
        $message = self::decode($this->upload($url, $author, Uuid::v7()->toRfc4122()));
        self::assertIsString($message['imageUrl']);
        $member = $this->openAccount('member@grrind.app');
        $code = self::decode($this->post($base.'/invite-code', [], $author->headers))['code'];
        self::assertSame(200, $this->post('/api/guilds/join', ['code' => $code], $member->headers)->getStatusCode());
        self::assertSame(200, $this->get($url, $member->headers)->getStatusCode(), 'Un nouveau membre voit le passé.');
        $subscription = self::decode($this->post($base.'/chat/subscription', [], $member->headers));
        self::assertIsString($subscription['token']);
        $parts = explode('.', $subscription['token']);
        $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($claims);
        self::assertSame(['subscribe' => [$subscription['topic']]], $claims['mercure']);
        self::assertIsInt($claims['exp']);
        self::assertEqualsWithDelta(time() + 300, $claims['exp'], 5);

        $this->send('DELETE', $base.'/members/'.$member->id->toRfc4122(), null, $author->headers);
        foreach ([$url, $message['imageUrl']] as $path) {
            self::assertSame(404, $this->get($path, $member->headers)->getStatusCode());
        }
        self::assertSame(404, $this->post($url, ['clientId' => Uuid::v7()->toRfc4122(), 'text' => 'Non'], $member->headers)->getStatusCode());
        self::assertSame(404, $this->post($base.'/chat/subscription', [], $member->headers)->getStatusCode());
        $this->post('/api/guilds/join', ['code' => $code], $member->headers);
        $this->send('POST', '/api/guilds/mine/leave', null, $member->headers);
        self::assertSame(404, $this->get($message['imageUrl'], $member->headers)->getStatusCode());
    }

    public function testCleanupKeepsReferencedImagesAndRemovesDissolvedAndRolledBackOnes(): void
    {
        [$author, $url] = $this->chat();
        $message = self::decode($this->upload($url, $author, Uuid::v7()->toRfc4122()));
        $cleanup = self::getContainer()->get(CleanupChatImages::class);
        self::assertInstanceOf(CleanupChatImages::class, $cleanup);
        $cleanup->clean();
        self::assertIsString($message['imageUrl']);
        self::assertSame(200, $this->get($message['imageUrl'], $author->headers)->getStatusCode());

        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $send = self::getContainer()->get(SendGuildMessage::class);
        self::assertInstanceOf(SendGuildMessage::class, $send);
        $connection->beginTransaction();
        $rolledBack = $send->send(Uuid::fromString(explode('/', $url)[3]), $author->id, Uuid::v7(), 'Annulé', new PreparedChatImage('encoded', 'original'));
        $connection->rollBack();
        $cleanup = self::getContainer()->get(CleanupChatImages::class);
        self::assertInstanceOf(CleanupChatImages::class, $cleanup);
        self::assertSame(1, $cleanup->clean());
        self::assertFalse($connection->fetchOne('SELECT 1 FROM community_guild_message WHERE id = ?', [$rolledBack->id()->toRfc4122()]));
        $signals = $connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE body LIKE '%ChatChanged%'");
        self::assertIsNumeric($signals);
        self::assertSame(1, (int) $signals, 'Le rollback emporte aussi son signal.');

        $this->send('DELETE', substr($url, 0, -\strlen('/chat/messages')), null, $author->headers);
        $cleanup = self::getContainer()->get(CleanupChatImages::class);
        self::assertInstanceOf(CleanupChatImages::class, $cleanup);
        self::assertSame(1, $cleanup->clean());
        self::assertSame(0, $cleanup->clean());
    }

    public function testSignalContainsNoConversationContentAndCanBeReplayed(): void
    {
        $published = [];
        $hub = new MockHub('http://hub', new StaticTokenProvider('test'), static function (Update $update) use (&$published): string {
            $published[] = $update;

            return 'event-id';
        });
        $handler = new ChatChangedHandler($hub);
        $event = new ChatChanged(Uuid::v7()->toRfc4122());
        $handler($event);
        $handler($event);
        self::assertCount(2, $published);
        foreach ($published as $update) {
            self::assertTrue($update->isPrivate());
            self::assertSame('{"type":"chat.changed"}', $update->getData());
            self::assertSame([ChatChangedHandler::topic($event->guildId)], $update->getTopics());
        }
    }

    public function testSendRateLimitIsEnforced(): void
    {
        [$author, $url] = $this->chat();
        $payload = ['clientId' => Uuid::v7()->toRfc4122(), 'text' => 'Bonjour'];
        for ($i = 0; $i < 30; ++$i) {
            self::assertSame(201, $this->post($url, $payload, $author->headers)->getStatusCode());
        }
        $response = $this->post($url, $payload, $author->headers);
        self::assertSame(429, $response->getStatusCode());
        self::assertTrue($response->headers->has('Retry-After'));
    }

    public function testImageWithTextAndConflictingReplay(): void
    {
        [$author, $url] = $this->chat();
        $clientId = Uuid::v7()->toRfc4122();
        $message = self::decode($this->upload($url, $author, $clientId, 'Photo'));
        self::assertSame('Photo', $message['text']);
        self::assertSame(409, $this->upload($url, $author, $clientId, 'Photo', 0xFFFFFF)->getStatusCode());
        self::assertSame(409, $this->upload($url, $author, $clientId, 'Autre texte')->getStatusCode());
        $other = $this->openAccount('other@grrind.app');
        $guild = self::decode($this->post('/api/guilds', ['name' => 'Autre'], $other->headers));
        self::assertIsString($guild['id']);
        self::assertIsString($message['id']);
        self::assertSame(404, $this->get('/api/guilds/'.$guild['id'].'/chat/messages/'.$message['id'].'/image', $other->headers)->getStatusCode());
    }

    public function testJsonCannotDescribeAServerFile(): void
    {
        [$author, $url] = $this->chat();
        $response = $this->post($url, ['clientId' => Uuid::v7()->toRfc4122(), 'text' => 'x', 'image' => ['path' => '/etc/passwd', 'originalName' => 'a.png', 'test' => true]], $author->headers);
        self::assertContains($response->getStatusCode(), [400, 422]);
    }

    private function upload(string $url, Account $author, string $clientId, string $text = '', int $color = 0): Response
    {
        $path = tempnam(sys_get_temp_dir(), 'chat-test-');
        self::assertIsString($path);
        $image = imagecreatetruecolor(2, 2);
        self::assertNotFalse($image);
        imagefill($image, 0, 0, $color);
        imagepng($image, $path);
        try {
            $this->client->request('POST', $url, ['clientId' => $clientId, 'text' => $text], ['image' => new UploadedFile($path, 'photo.png', 'image/png', test: true)], ['CONTENT_TYPE' => 'multipart/form-data', 'HTTP_AUTHORIZATION' => $author->headers['Authorization']]);

            return $this->client->getResponse();
        } finally {
            unlink($path);
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
