<?php

declare(strict_types=1);

namespace App\Tests\Community;

use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;

final class GuildEmblemTest extends ApiTestCase
{
    private const array DEFAULT = ['symbol' => 'DUMBBELL', 'backgroundColor' => 'NIGHT', 'symbolColor' => 'CYAN'];
    private const array CUSTOM = ['symbol' => 'HEART', 'backgroundColor' => 'MINT', 'symbolColor' => 'NIGHT'];

    public function testTheDatabaseDefaultIsReadableForAGuildWithoutAnExplicitEmblem(): void
    {
        $founder = $this->openAccount();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $id = '019a844d-bb21-7000-8000-000000000357';
        $db->insert('community_guild', ['id' => $id, 'name' => 'Ancienne guilde', 'created_by' => $founder->id->toRfc4122(), 'created_at' => '2026-11-01 00:00:00+00']);
        $db->insert('community_guild_membership', ['id' => '019a844d-bb21-7000-8000-000000000358', 'guild_id' => $id, 'player_id' => $founder->id->toRfc4122(), 'role' => 'FOUNDER', 'joined_at' => '2026-11-01 00:00:00+00']);

        self::assertSame(self::DEFAULT, self::decode($this->get('/api/guilds/'.$id, $founder->headers))['emblem']);
        self::assertSame(
            ['id' => $id, 'name' => 'Ancienne guilde', 'emblem' => self::DEFAULT],
            self::decode($this->get('/api/players/'.$founder->id->toRfc4122(), $founder->headers))['guild'],
        );
    }

    public function testTheFounderSavesAnEmblemAndNameUpdatesPreserveIt(): void
    {
        $founder = $this->openAccount();
        $created = $this->post('/api/guilds', ['name' => 'Les Lève-Tôt'], $founder->headers);
        self::assertSame(201, $created->getStatusCode());
        $guild = self::decode($created);
        self::assertSame(self::DEFAULT, $guild['emblem']);
        self::assertIsString($guild['id']);
        $id = $guild['id'];

        $updated = $this->send('PATCH', '/api/guilds/'.$id, ['emblem' => self::CUSTOM], $founder->headers);
        self::assertSame(200, $updated->getStatusCode(), (string) $updated->getContent());
        self::assertSame('Les Lève-Tôt', self::decode($updated)['name']);
        self::assertSame(self::CUSTOM, self::decode($updated)['emblem']);

        $renamed = $this->send('PATCH', '/api/guilds/'.$id, ['name' => 'Les Couche-Tard'], $founder->headers);
        self::assertSame(200, $renamed->getStatusCode());
        self::assertSame(self::CUSTOM, self::decode($renamed)['emblem']);
        foreach (['/api/guilds/'.$id, '/api/guilds/mine'] as $uri) {
            $body = self::decode($this->get($uri, $founder->headers));
            if ('/api/guilds/mine' === $uri) {
                self::assertIsArray($body['guild']);
                $body = $body['guild'];
            }
            self::assertSame(self::CUSTOM, $body['emblem']);
            self::assertSame('Les Couche-Tard', $body['name']);
        }

        $member = $this->openAccount('member@grrind.app', 'Membre');
        $code = self::decode($this->post('/api/guilds/'.$id.'/invite-code', [], $founder->headers))['code'];
        $joined = $this->post('/api/guilds/join', ['code' => $code], $member->headers);
        self::assertSame(200, $joined->getStatusCode());
        self::assertSame(self::CUSTOM, self::decode($joined)['emblem']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidChanges(): iterable
    {
        yield 'aucun changement' => [[]];
        yield 'emblème null' => [['emblem' => null]];
        yield 'emblème incomplet' => [['emblem' => ['symbol' => 'HEART']]];
        yield 'symbole inconnu' => [['emblem' => ['symbol' => 'UPLOAD'] + self::CUSTOM]];
        yield 'couleur libre' => [['emblem' => ['backgroundColor' => '#050816'] + self::CUSTOM]];
        yield 'symbole null' => [['emblem' => ['symbol' => null] + self::CUSTOM]];
        yield 'fond null' => [['emblem' => ['backgroundColor' => null] + self::CUSTOM]];
        yield 'couleur symbole invalide' => [['emblem' => ['symbolColor' => 'RED'] + self::CUSTOM]];
        yield 'même couleur' => [['emblem' => ['symbolColor' => 'MINT'] + self::CUSTOM]];
        yield 'deux couleurs claires' => [['emblem' => ['symbolColor' => 'CHALK'] + self::CUSTOM]];
        yield 'deux couleurs nuit' => [['emblem' => ['backgroundColor' => 'NIGHT', 'symbolColor' => 'NIGHT'] + self::CUSTOM]];
        yield 'nom blanc et emblème' => [['name' => ' ', 'emblem' => self::CUSTOM]];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidChanges')]
    public function testInvalidChangesLeaveTheGuildIntact(array $payload): void
    {
        $founder = $this->openAccount();
        $guild = self::decode($this->post('/api/guilds', ['name' => 'Intacte'], $founder->headers));
        self::assertIsString($guild['id']);
        $uri = '/api/guilds/'.$guild['id'];

        $response = $this->send('PATCH', $uri, $payload, $founder->headers);
        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        $unchanged = self::decode($this->get($uri, $founder->headers));
        self::assertSame(self::DEFAULT, $unchanged['emblem']);
        self::assertSame('Intacte', $unchanged['name']);
    }

    public function testOnlyTheCurrentFounderMayChangeTheEmblem(): void
    {
        $founder = $this->openAccount();
        $member = $this->openAccount('member@grrind.app', 'Membre');
        $stranger = $this->openAccount('stranger@grrind.app', 'Tiers');
        $guild = self::decode($this->post('/api/guilds', ['name' => 'Privée'], $founder->headers));
        self::assertIsString($guild['id']);
        $uri = '/api/guilds/'.$guild['id'];
        $code = self::decode($this->post($uri.'/invite-code', [], $founder->headers))['code'];
        self::assertSame(200, $this->post('/api/guilds/join', ['code' => $code], $member->headers)->getStatusCode());

        foreach ([[$member, 403], [$stranger, 404], [null, 401]] as [$actor, $status]) {
            $response = $this->send('PATCH', $uri, ['emblem' => self::CUSTOM], $actor instanceof Account ? $actor->headers : []);
            self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        }
        self::assertSame(self::DEFAULT, self::decode($this->get($uri, $founder->headers))['emblem']);

        self::assertSame(204, $this->post('/api/guilds/mine/leave', [], $founder->headers)->getStatusCode());
        $updated = $this->send('PATCH', $uri, ['name' => 'Héritée', 'emblem' => self::CUSTOM], $member->headers);
        self::assertSame(200, $updated->getStatusCode(), (string) $updated->getContent());
        self::assertSame(self::CUSTOM, self::decode($updated)['emblem']);
        self::assertSame('Héritée', self::decode($updated)['name']);
    }
}
