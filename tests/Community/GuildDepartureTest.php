<?php

declare(strict_types=1);

namespace App\Tests\Community;

use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quitter, exclure, succéder.
 *
 * Une guilde qu'on ne peut pas quitter est un piège, et un fondateur qui s'en va ne doit
 * pas emporter la guilde avec lui. La succession n'est pas une fonctionnalité : c'est ce
 * qui rend le départ possible — sans elle, la seule sortie honnête du fondateur serait de
 * dissoudre sur le dos des autres.
 */
final class GuildDepartureTest extends ApiTestCase
{
    public function testAPlainMemberSimplyLeaves(): void
    {
        [$founder, $member, $guildId] = $this->guildOfTwo();

        $response = $this->send('POST', '/api/guilds/mine/leave', null, $member->headers);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getContent());
        self::assertNull(self::decode($this->get('/api/guilds/mine', $member->headers))['guild']);

        // La guilde survit, et le fondateur la voit rétrécie.
        $guild = self::decode($this->get('/api/guilds/'.$guildId, $founder->headers));
        self::assertSame(1, $guild['memberCount']);
    }

    /** Quitter libère : c'est le point qui rend la guilde autre chose qu'un piège. */
    public function testLeavingFreesThePlayerToJoinElsewhere(): void
    {
        [, $member] = $this->guildOfTwo();

        $this->send('POST', '/api/guilds/mine/leave', null, $member->headers);

        self::assertSame(
            Response::HTTP_CREATED,
            $this->post('/api/guilds', ['name' => 'La sienne'], $member->headers)->getStatusCode(),
        );
    }

    public function testAPlayerWithoutAGuildHasNothingToLeave(): void
    {
        $response = $this->send('POST', '/api/guilds/mine/leave', null, $this->openAccount()->headers);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('https://grrind.app/problems/guild-not-found', self::decode($response)['type']);
    }

    public function testRefusesAnAnonymousLeave(): void
    {
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->send('POST', '/api/guilds/mine/leave')->getStatusCode());
    }

    /** **La règle du ticket** : le fondateur qui part transmet au membre le plus ancien. */
    public function testTheFounderHandsTheGuildToTheOldestMember(): void
    {
        $founder = $this->openAccount();
        $guildId = $this->foundGuild($founder);
        $code = $this->issueCode($founder, $guildId);

        $carla = $this->openAccount('carla@grrind.app', 'Carla');
        $this->join($carla, $code);

        $dan = $this->openAccount('dan@grrind.app', 'Dan');
        $this->join($dan, $code);

        self::assertSame(Response::HTTP_NO_CONTENT, $this->send('POST', '/api/guilds/mine/leave', null, $founder->headers)->getStatusCode());

        // Carla est entrée avant Dan : c'est elle qui hérite, sans que personne l'ait désignée.
        $guild = self::decode($this->get('/api/guilds/mine', $carla->headers))['guild'];
        self::assertIsArray($guild);
        self::assertSame('FOUNDER', $guild['role']);

        $seenByDan = self::decode($this->get('/api/guilds/mine', $dan->headers))['guild'];
        self::assertIsArray($seenByDan);
        self::assertSame('MEMBER', $seenByDan['role']);
    }

    /** Le nouveau fondateur peut réellement diriger : la succession n'est pas décorative. */
    public function testTheSuccessorCanActuallyLeadTheGuild(): void
    {
        $founder = $this->openAccount();
        $guildId = $this->foundGuild($founder);
        $heir = $this->openAccount('carla@grrind.app', 'Carla');
        $this->join($heir, $this->issueCode($founder, $guildId));

        $this->send('POST', '/api/guilds/mine/leave', null, $founder->headers);

        self::assertSame(
            Response::HTTP_OK,
            $this->send('PATCH', '/api/guilds/'.$guildId, ['name' => 'Renommée par l\'héritière'], $heir->headers)->getStatusCode(),
        );
    }

    /** Et l'ancien fondateur n'a plus rien : partir, c'est vraiment partir. */
    public function testTheDepartedFounderLosesEverything(): void
    {
        $founder = $this->openAccount();
        $guildId = $this->foundGuild($founder);
        $this->join($this->openAccount('carla@grrind.app', 'Carla'), $this->issueCode($founder, $guildId));

        $this->send('POST', '/api/guilds/mine/leave', null, $founder->headers);

        self::assertSame(Response::HTTP_NOT_FOUND, $this->get('/api/guilds/'.$guildId, $founder->headers)->getStatusCode());
    }

    /** Le dernier à partir éteint la lumière : une guilde vide n'a personne pour la dissoudre. */
    public function testTheLastFounderDissolvesTheGuildOnHisWayOut(): void
    {
        $founder = $this->openAccount();
        $guildId = $this->foundGuild($founder);
        $this->issueCode($founder, $guildId);

        self::assertSame(Response::HTTP_NO_CONTENT, $this->send('POST', '/api/guilds/mine/leave', null, $founder->headers)->getStatusCode());

        self::assertSame(0, $this->countGuilds(), 'Une guilde vide est une ligne que plus rien ne peut atteindre.');
        self::assertSame(0, $this->countInviteCodes(), 'Le code d\'invitation part avec elle, sinon il mènerait à une guilde disparue.');
    }

    public function testTheFounderExcludesAMember(): void
    {
        [$founder, $member, $guildId] = $this->guildOfTwo();

        $response = $this->send('DELETE', '/api/guilds/'.$guildId.'/members/'.$member->id->toRfc4122(), null, $founder->headers);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getContent());
        self::assertNull(self::decode($this->get('/api/guilds/mine', $member->headers))['guild']);
    }

    /** Pas de liste noire en v1 : le recours du fondateur est de révoquer le code. */
    public function testAnExcludedPlayerMayComeBackWithAValidCode(): void
    {
        [$founder, $member, $guildId] = $this->guildOfTwo();
        $code = $this->issueCode($founder, $guildId);

        $this->send('DELETE', '/api/guilds/'.$guildId.'/members/'.$member->id->toRfc4122(), null, $founder->headers);

        self::assertSame(Response::HTTP_OK, $this->join($member, $code)->getStatusCode());
    }

    public function testAMemberCannotExcludeAnyone(): void
    {
        [$founder, $member, $guildId] = $this->guildOfTwo();

        $response = $this->send('DELETE', '/api/guilds/'.$guildId.'/members/'.$founder->id->toRfc4122(), null, $member->headers);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('https://grrind.app/problems/forbidden', self::decode($response)['type']);
    }

    public function testAStrangerGetsNotFoundRatherThanForbidden(): void
    {
        [$founder, , $guildId] = $this->guildOfTwo();
        $stranger = $this->openAccount('dan@grrind.app', 'Dan');

        $response = $this->send('DELETE', '/api/guilds/'.$guildId.'/members/'.$founder->id->toRfc4122(), null, $stranger->headers);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('https://grrind.app/problems/guild-not-found', self::decode($response)['type']);
    }

    /**
     * L'exclusion retire une ligne, point. Le départ, lui, sait transmettre ou dissoudre :
     * un fondateur qui s'excluerait laisserait une guilde que plus personne ne dirige.
     */
    public function testTheFounderCannotExcludeHimself(): void
    {
        [$founder, , $guildId] = $this->guildOfTwo();

        $response = $this->send('DELETE', '/api/guilds/'.$guildId.'/members/'.$founder->id->toRfc4122(), null, $founder->headers);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame('https://grrind.app/problems/founder-cannot-exclude-himself', self::decode($response)['type']);

        // Et rien n'a bougé : le refus est prononcé avant toute écriture.
        self::assertSame(2, self::decode($this->get('/api/guilds/'.$guildId, $founder->headers))['memberCount']);
    }

    public function testExcludingSomeoneWhoAlreadyLeft(): void
    {
        [$founder, $member, $guildId] = $this->guildOfTwo();
        $this->send('POST', '/api/guilds/mine/leave', null, $member->headers);

        $response = $this->send('DELETE', '/api/guilds/'.$guildId.'/members/'.$member->id->toRfc4122(), null, $founder->headers);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('https://grrind.app/problems/player-is-not-a-member', self::decode($response)['type']);
    }

    public function testAMalformedPlayerIdentifierIsJustSomeoneWhoIsNotThere(): void
    {
        [$founder, , $guildId] = $this->guildOfTwo();

        $response = $this->send('DELETE', '/api/guilds/'.$guildId.'/members/pas-un-uuid', null, $founder->headers);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('https://grrind.app/problems/player-is-not-a-member', self::decode($response)['type']);
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

    private function join(Account $player, string $code): Response
    {
        return $this->post('/api/guilds/join', ['code' => $code], $player->headers);
    }

    /**
     * @return array{Account, Account, string}
     */
    private function guildOfTwo(): array
    {
        $founder = $this->openAccount();
        $member = $this->openAccount('carla@grrind.app', 'Carla');
        $guildId = $this->foundGuild($founder);

        self::assertSame(Response::HTTP_OK, $this->join($member, $this->issueCode($founder, $guildId))->getStatusCode());

        return [$founder, $member, $guildId];
    }

    private function countGuilds(): int
    {
        return $this->countRowsOf('community_guild');
    }

    private function countInviteCodes(): int
    {
        return $this->countRowsOf('community_guild_invite_code');
    }

    private function countRowsOf(string $table): int
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        $count = $connection->fetchOne('SELECT COUNT(*) FROM '.$table);
        self::assertIsNumeric($count);

        return (int) $count;
    }
}
