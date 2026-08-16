<?php

declare(strict_types=1);

namespace App\Tests\Community;

use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Fonder, renommer, dissoudre — et surtout : **qui reçoit quoi quand il n'a pas le droit**.
 *
 * La moitié de ces tests porte sur la distinction 404 / 403, parce que c'est elle qui
 * décide si l'API est un test d'existence sur des UUID. Un test qui vérifierait seulement
 * « ça refuse » les laisserait diverger sans rien dire.
 */
final class GuildLifecycleTest extends ApiTestCase
{
    public function testFoundingAGuildMakesTheCallerItsFounder(): void
    {
        $founder = $this->openAccount();

        $response = $this->post('/api/guilds', ['name' => 'Les Lève-Tôt'], $founder->headers);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);

        self::assertSame('Les Lève-Tôt', $body['name']);
        self::assertSame('FOUNDER', $body['role']);
        self::assertSame(1, $body['memberCount'], 'Fonder une guilde, c\'est y entrer.');
        self::assertSame(30, $body['capacity'], 'La capacité vient de l\'équilibrage, pas d\'une constante du client.');
        self::assertIsString($body['id']);
        self::assertTrue(Uuid::isValid($body['id']));
    }

    public function testRefusesASecondGuildToTheSamePlayer(): void
    {
        $founder = $this->openAccount();
        $this->post('/api/guilds', ['name' => 'Les Lève-Tôt'], $founder->headers);

        $response = $this->post('/api/guilds', ['name' => 'Les Couche-Tard'], $founder->headers);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame('https://grrind.app/problems/player-already-in-a-guild', self::decode($response)['type']);
    }

    public function testRefusesAnAnonymousCaller(): void
    {
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->post('/api/guilds', ['name' => 'Les Lève-Tôt'])->getStatusCode());
    }

    public function testRefusesABlankName(): void
    {
        $response = $this->post('/api/guilds', ['name' => '   '], $this->openAccount()->headers);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function testRefusesANameBeyondTheLimit(): void
    {
        $response = $this->post('/api/guilds', ['name' => str_repeat('a', 41)], $this->openAccount()->headers);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    /** Le nom n'est pas unique, et ce n'est pas un oubli : aucune recherche de guilde n'existe. */
    public function testTwoGuildsMayShareAName(): void
    {
        $this->foundGuild($this->openAccount(), 'Les Lève-Tôt');

        $second = $this->post('/api/guilds', ['name' => 'Les Lève-Tôt'], $this->openAccount('carla@grrind.app', 'Carla')->headers);

        self::assertSame(Response::HTTP_CREATED, $second->getStatusCode());
    }

    public function testTheFounderRenamesTheGuild(): void
    {
        $founder = $this->openAccount();
        $guildId = $this->foundGuild($founder, 'Les Lève-Tôt');

        $response = $this->send('PATCH', '/api/guilds/'.$guildId, ['name' => 'Les Couche-Tard'], $founder->headers);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('Les Couche-Tard', self::decode($response)['name']);
    }

    /**
     * Le cœur de la règle : un joueur qui n'est pas membre reçoit **404**, pas 403. Un 403
     * lui confirmerait qu'une guilde porte cet UUID, et les UUID v7 se devinent par plage
     * temporelle.
     */
    public function testANonMemberGetsNotFoundRatherThanForbidden(): void
    {
        $guildId = $this->foundGuild($this->openAccount(), 'Les Lève-Tôt');
        $stranger = $this->openAccount('carla@grrind.app', 'Carla');

        $response = $this->send('PATCH', '/api/guilds/'.$guildId, ['name' => 'Détournée'], $stranger->headers);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('https://grrind.app/problems/guild-not-found', self::decode($response)['type']);
    }

    /**
     * Et la contrepartie qui donne son sens au test précédent : une guilde **qui n'existe
     * pas** doit rendre exactement la même chose. Deux réponses identiques au bit près, ou
     * la distinction précédente ne sert à rien.
     */
    public function testAnUnknownGuildIsIndistinguishableFromOneWeAreNotIn(): void
    {
        $guildId = $this->foundGuild($this->openAccount(), 'Les Lève-Tôt');
        $stranger = $this->openAccount('carla@grrind.app', 'Carla');

        $hidden = $this->send('PATCH', '/api/guilds/'.$guildId, ['name' => 'Détournée'], $stranger->headers);
        $unknown = $this->send('PATCH', '/api/guilds/'.Uuid::v7()->toRfc4122(), ['name' => 'Détournée'], $stranger->headers);

        self::assertSame($hidden->getStatusCode(), $unknown->getStatusCode());
        self::assertSame(self::decode($hidden), self::decode($unknown));
    }

    /** Un `{id}` qui n'est même pas un UUID ne doit pas non plus se distinguer. */
    public function testAMalformedIdentifierGetsTheSameNotFound(): void
    {
        $response = $this->send('PATCH', '/api/guilds/pas-un-uuid', ['name' => 'Détournée'], $this->openAccount()->headers);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('https://grrind.app/problems/guild-not-found', self::decode($response)['type']);
    }

    public function testANonFounderMemberGetsForbidden(): void
    {
        [, $member, $guildId] = $this->guildOfTwo();

        $response = $this->send('PATCH', '/api/guilds/'.$guildId, ['name' => 'Détournée'], $member->headers);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('https://grrind.app/problems/forbidden', self::decode($response)['type']);
    }

    public function testTheFounderDissolvesTheGuild(): void
    {
        $founder = $this->openAccount();
        $guildId = $this->foundGuild($founder, 'Les Lève-Tôt');

        $response = $this->send('DELETE', '/api/guilds/'.$guildId, null, $founder->headers);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());

        // Elle a bien disparu : le fondateur peut en refonder une, ce que l'index unique
        // lui interdirait si son adhésion avait survécu.
        self::assertSame(
            Response::HTTP_CREATED,
            $this->post('/api/guilds', ['name' => 'La suivante'], $founder->headers)->getStatusCode(),
        );
    }

    public function testDissolvingFreesEveryMemberAndNotOnlyTheFounder(): void
    {
        [$founder, $member, $guildId] = $this->guildOfTwo();

        $this->send('DELETE', '/api/guilds/'.$guildId, null, $founder->headers);

        // Le vrai risque de la dissolution : une adhésion orpheline enfermerait son joueur
        // hors de toute guilde, sans que rien ne le lui dise.
        self::assertSame(
            Response::HTTP_CREATED,
            $this->post('/api/guilds', ['name' => 'La sienne'], $member->headers)->getStatusCode(),
        );
    }

    public function testANonFounderCannotDissolve(): void
    {
        [, $member, $guildId] = $this->guildOfTwo();

        $response = $this->send('DELETE', '/api/guilds/'.$guildId, null, $member->headers);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testAStrangerCannotDissolve(): void
    {
        $guildId = $this->foundGuild($this->openAccount(), 'Les Lève-Tôt');
        $stranger = $this->openAccount('carla@grrind.app', 'Carla');

        $response = $this->send('DELETE', '/api/guilds/'.$guildId, null, $stranger->headers);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    private function foundGuild(Account $founder, string $name): string
    {
        $response = $this->post('/api/guilds', ['name' => $name], $founder->headers);
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());

        $id = self::decode($response)['id'];
        self::assertIsString($id);

        return $id;
    }

    /**
     * Une guilde avec un fondateur et un membre ordinaire. Tant que `POST /api/guilds/join`
     * n'existe pas (#116), le second entre par la base : ce qu'on démontre ici est
     * l'autorisation, pas le chemin d'entrée.
     *
     * @return array{Account, Account, string}
     */
    private function guildOfTwo(): array
    {
        $founder = $this->openAccount();
        $member = $this->openAccount('carla@grrind.app', 'Carla');
        $guildId = $this->foundGuild($founder, 'Les Lève-Tôt');

        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement(
            'INSERT INTO community_guild_membership (id, guild_id, player_id, role, joined_at) VALUES (?, ?, ?, ?, NOW())',
            [Uuid::v7()->toRfc4122(), $guildId, $member->id->toRfc4122(), 'MEMBER'],
        );

        return [$founder, $member, $guildId];
    }
}
