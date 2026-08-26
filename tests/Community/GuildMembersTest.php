<?php

declare(strict_types=1);

namespace App\Tests\Community;

use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\Uid\Uuid;

/**
 * La liste des membres : c'est à ça que la guilde sert en v1.
 *
 * Le test qui compte le plus est {@see self::testTheNumberOfQueriesDoesNotFollowTheNumberOfMembers()} :
 * il est la seule chose qui empêche les deux ports batch de redevenir des ports unitaires.
 * Tous les autres passeraient encore après une refonte en N+1.
 */
final class GuildMembersTest extends ApiTestCase
{
    public function testAPlayerWithoutAGuildGetsAnExplicitAnswer(): void
    {
        $response = $this->get('/api/guilds/mine', $this->openAccount()->headers);

        // Pas une erreur : ouvrir l'onglet sans guilde est une situation normale, et c'est
        // l'écran qui invite à en fonder une.
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertNull(self::decode($response)['guild']);
    }

    /**
     * `/api/guilds/mine` et `/api/guilds/{id}` s'attrapent mutuellement. Ce test et le
     * précédent sont ce qui tient l'ordre de déclaration : si `{id}` passait devant,
     * « mine » serait lu comme un identifiant et rendrait 404.
     */
    public function testMineIsNotSwallowedByTheIdentifierRoute(): void
    {
        $founder = $this->openAccount();
        $guildId = $this->foundGuild($founder);

        $response = $this->get('/api/guilds/mine', $founder->headers);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $guild = self::decode($response)['guild'];
        self::assertIsArray($guild);
        self::assertSame($guildId, $guild['id']);
    }

    public function testTheFounderSeesHimselfInTheList(): void
    {
        $founder = $this->openAccount();
        $this->foundGuild($founder);

        $guild = self::decode($this->get('/api/guilds/mine', $founder->headers))['guild'];
        self::assertIsArray($guild);

        $members = $guild['members'];
        self::assertIsArray($members);
        self::assertCount(1, $members);

        $member = $members[0];
        self::assertIsArray($member);
        self::assertSame($founder->id->toRfc4122(), $member['id']);
        self::assertSame('Bob', $member['displayName']);
        self::assertSame('FOUNDER', $member['role']);
        self::assertIsString($member['joinedAt']);
        self::assertIsString($member['registeredAt']);
    }

    /**
     * Un joueur qui vient de s'inscrire n'a pas de ligne de progression — c'est le premier
     * crédit qui la pose. Il doit s'afficher quand même, à l'état neutre, cinq zéros compris
     * (#176) — et surtout sans faire disparaître la liste entière à cause de lui.
     */
    public function testAMemberWithoutProgressionShowsANeutralState(): void
    {
        $founder = $this->openAccount();
        $this->foundGuild($founder);

        $member = self::membersOf($this->get('/api/guilds/mine', $founder->headers))[0];

        self::assertSame(1, $member['level']);
        self::assertSame(0, $member['xpIntoLevel']);
        self::assertNull($member['title']);
        self::assertSame(
            ['strength' => 0, 'endurance' => 0, 'mobility' => 0, 'dexterity' => 0, 'vitality' => 0],
            $member['attributes'],
        );
    }

    /** Ce que la liste ne dit pas : rien du compte, seulement du profil public. */
    public function testTheListNeverLeaksAccountData(): void
    {
        $founder = $this->openAccount();
        $this->foundGuild($founder);

        $member = self::membersOf($this->get('/api/guilds/mine', $founder->headers))[0];

        self::assertArrayNotHasKey('email', $member);
        self::assertArrayNotHasKey('timezone', $member);
        self::assertArrayNotHasKey('roles', $member);
        self::assertArrayNotHasKey('password', $member);
    }

    public function testTheFounderComesFirstThenMembersByJoinDate(): void
    {
        $founder = $this->openAccount();
        $guildId = $this->foundGuild($founder);
        $code = $this->issueCode($founder, $guildId);

        $carla = $this->openAccount('carla@grrind.app', 'Carla');
        $this->post('/api/guilds/join', ['code' => $code], $carla->headers);

        $dan = $this->openAccount('dan@grrind.app', 'Dan');
        $this->post('/api/guilds/join', ['code' => $code], $dan->headers);

        $names = array_column(self::membersOf($this->get('/api/guilds/mine', $founder->headers)), 'displayName');

        self::assertSame(['Bob', 'Carla', 'Dan'], $names);
    }

    /** Deux membres homonymes sont un cas normal en v1 : c'est l'`id` qui distingue. */
    public function testTwoMembersMayShareADisplayName(): void
    {
        $founder = $this->openAccount();
        $guildId = $this->foundGuild($founder);
        $code = $this->issueCode($founder, $guildId);

        $twin = $this->openAccount('carla@grrind.app', 'Bob');
        $this->post('/api/guilds/join', ['code' => $code], $twin->headers);

        $members = self::membersOf($this->get('/api/guilds/mine', $founder->headers));

        self::assertSame(['Bob', 'Bob'], array_column($members, 'displayName'));

        /** @var list<string> $ids */
        $ids = array_column($members, 'id');
        self::assertCount(2, array_unique($ids));
    }

    public function testAMemberSeesTheGuildByItsIdentifier(): void
    {
        $founder = $this->openAccount();
        $guildId = $this->foundGuild($founder);
        $member = $this->openAccount('carla@grrind.app', 'Carla');
        $this->post('/api/guilds/join', ['code' => $this->issueCode($founder, $guildId)], $member->headers);

        $response = $this->get('/api/guilds/'.$guildId, $member->headers);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);
        self::assertSame($guildId, $body['id']);
        self::assertSame('MEMBER', $body['role'], 'Le rôle rendu est celui de l\'appelant, pas une propriété de la guilde.');
        self::assertIsArray($body['members']);
        self::assertCount(2, $body['members']);
    }

    public function testAStrangerCannotSeeTheGuild(): void
    {
        $guildId = $this->foundGuild($this->openAccount());
        $stranger = $this->openAccount('carla@grrind.app', 'Carla');

        $hidden = $this->get('/api/guilds/'.$guildId, $stranger->headers);
        $unknown = $this->get('/api/guilds/'.Uuid::v7()->toRfc4122(), $stranger->headers);

        self::assertSame(Response::HTTP_NOT_FOUND, $hidden->getStatusCode());
        self::assertSame(self::decode($hidden), self::decode($unknown), 'Une guilde invisible et une guilde inexistante ne se distinguent pas.');
    }

    public function testRefusesAnAnonymousCaller(): void
    {
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->get('/api/guilds/mine')->getStatusCode());
    }

    /**
     * **Le test qui protège les deux ports.** Une guilde de dix membres doit coûter
     * exactement le même nombre de requêtes qu'une de deux — c'est la définition d'un port
     * batch, et la seule chose qu'une revue de code ne verra pas se perdre.
     *
     * Le nombre exact n'est pas figé : l'assertion porte sur l'**égalité entre les deux
     * tailles**, pas sur une constante qu'il faudrait recaler à chaque optimisation. Ce
     * qu'on interdit, c'est que le compte suive la taille de la guilde.
     */
    public function testTheNumberOfQueriesDoesNotFollowTheNumberOfMembers(): void
    {
        $founder = $this->openAccount();
        $guildId = $this->foundGuild($founder);

        $small = $this->countQueriesOfGuildPage($founder);

        $this->fillUpTo($guildId, 12);

        $large = $this->countQueriesOfGuildPage($founder);

        self::assertGreaterThan(0, $small, 'Un compteur qui ne compte rien ferait passer ce test sans rien vérifier.');
        self::assertSame(
            $small,
            $large,
            'Le nombre de requêtes suit le nombre de membres : un des deux ports batch est redevenu unitaire, et c\'est un N+1 qui n\'apparaîtra qu\'en production.',
        );
    }

    private function countQueriesOfGuildPage(Account $player): int
    {
        $this->client->enableProfiler();

        $response = $this->get('/api/guilds/mine', $player->headers);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $profile = $this->client->getProfile();
        self::assertInstanceOf(Profile::class, $profile, 'Le profiler doit être activé en test — voir config/packages/framework.yaml.');

        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        return $collector->getQueryCount();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function membersOf(Response $response): array
    {
        $guild = self::decode($response)['guild'] ?? self::decode($response);
        self::assertIsArray($guild);

        $members = $guild['members'];
        self::assertIsArray($members);

        /** @var list<array<string, mixed>> $members */
        return $members;
    }

    private function foundGuild(Account $founder, string $name = 'Les Lève-Tôt'): string
    {
        $response = $this->post('/api/guilds', ['name' => $name], $founder->headers);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());

        $id = self::decode($response)['id'];
        self::assertIsString($id);

        return $id;
    }

    private function issueCode(Account $founder, string $guildId): string
    {
        $response = $this->post('/api/guilds/'.$guildId.'/invite-code', [], $founder->headers);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());

        $code = self::decode($response)['code'];
        self::assertIsString($code);

        return $code;
    }

    /**
     * Des membres posés en base, sans compte derrière eux : ce test-ci mesure le nombre de
     * requêtes, et un joueur sans profil est écarté de la liste — ce qui suffit à faire
     * varier le compte si un port dérivait vers l'unitaire.
     */
    private function fillUpTo(string $guildId, int $size): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        $current = $connection->fetchOne('SELECT COUNT(*) FROM community_guild_membership WHERE guild_id = ?', [$guildId]);
        self::assertIsNumeric($current);

        for ($seat = (int) $current; $seat < $size; ++$seat) {
            $player = Uuid::v7();

            $connection->executeStatement(
                'INSERT INTO identity_user (id, email, roles, display_name, timezone, registered_at, disabled_notification_categories) VALUES (?, ?, ?, ?, ?, NOW(), ?)',
                [$player->toRfc4122(), 'membre'.$seat.'@grrind.app', '[]', 'Membre '.$seat, 'Europe/Paris', '[]'],
            );

            $connection->executeStatement(
                'INSERT INTO community_guild_membership (id, guild_id, player_id, role, joined_at) VALUES (?, ?, ?, ?, NOW())',
                [Uuid::v7()->toRfc4122(), $guildId, $player->toRfc4122(), 'MEMBER'],
            );
        }
    }
}
