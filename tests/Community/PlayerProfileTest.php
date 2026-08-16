<?php

declare(strict_types=1);

namespace App\Tests\Community;

use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /api/players/{id}` — la seule route de l'API qui sert les données de quelqu'un
 * d'autre, et celle qui a fait réécrire un invariant.
 *
 * Le test qui porte la décision est
 * {@see self::testAStrangerAndAnUnknownUuidAreIndistinguishable()} : c'est lui qui empêche
 * la route de devenir un test d'existence sur des UUID v7, lesquels encodent leur instant
 * de création et se devinent donc par plage temporelle.
 */
final class PlayerProfileTest extends ApiTestCase
{
    public function testAPlayerSeesHisOwnPublicProfile(): void
    {
        $player = $this->openAccount();

        $response = $this->get('/api/players/'.$player->id->toRfc4122(), $player->headers);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);
        self::assertSame($player->id->toRfc4122(), $body['id']);
        self::assertSame('Bob', $body['displayName']);
        self::assertSame(1, $body['level']);
        self::assertIsString($body['registeredAt']);
    }

    /** Se voir soi-même ne dépend pas d'avoir une guilde : c'est la première clause du voter. */
    public function testSeeingOneselfWorksWithoutAnyGuild(): void
    {
        $loner = $this->openAccount();

        self::assertSame(
            Response::HTTP_OK,
            $this->get('/api/players/'.$loner->id->toRfc4122(), $loner->headers)->getStatusCode(),
        );
    }

    public function testATeammateIsVisible(): void
    {
        [$founder, $member] = $this->guildOfTwo();

        $seenByFounder = $this->get('/api/players/'.$member->id->toRfc4122(), $founder->headers);
        self::assertSame(Response::HTTP_OK, $seenByFounder->getStatusCode(), (string) $seenByFounder->getContent());
        self::assertSame('Carla', self::decode($seenByFounder)['displayName']);

        // Et dans l'autre sens : le voter ne privilégie pas le fondateur.
        $seenByMember = $this->get('/api/players/'.$founder->id->toRfc4122(), $member->headers);
        self::assertSame(Response::HTTP_OK, $seenByMember->getStatusCode());
        self::assertSame('Bob', self::decode($seenByMember)['displayName']);
    }

    public function testAStrangerIsNotVisible(): void
    {
        $stranger = $this->openAccount('dan@grrind.app', 'Dan');
        [$founder] = $this->guildOfTwo();

        $response = $this->get('/api/players/'.$founder->id->toRfc4122(), $stranger->headers);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('https://grrind.app/problems/player-not-found', self::decode($response)['type']);
    }

    /**
     * **Le test qui porte la décision du ticket.** Un compte réel qu'on n'a pas le droit de
     * voir et un UUID qui ne désigne personne doivent rendre la même réponse au champ près.
     * Les vérifier séparément laisserait les deux chemins diverger, et la route
     * redeviendrait un oracle.
     */
    public function testAStrangerAndAnUnknownUuidAreIndistinguishable(): void
    {
        $stranger = $this->openAccount('dan@grrind.app', 'Dan');
        [$founder] = $this->guildOfTwo();

        $forbidden = $this->get('/api/players/'.$founder->id->toRfc4122(), $stranger->headers);
        $unknown = $this->get('/api/players/'.Uuid::v7()->toRfc4122(), $stranger->headers);

        self::assertSame($forbidden->getStatusCode(), $unknown->getStatusCode());
        self::assertSame(self::decode($forbidden), self::decode($unknown));
        self::assertNotSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode(), 'Un 403 confirmerait qu\'un compte porte cet UUID.');
    }

    public function testAMalformedIdentifierGetsTheSameAnswer(): void
    {
        $player = $this->openAccount();

        $malformed = $this->get('/api/players/pas-un-uuid', $player->headers);
        $unknown = $this->get('/api/players/'.Uuid::v7()->toRfc4122(), $player->headers);

        self::assertSame(Response::HTTP_NOT_FOUND, $malformed->getStatusCode());
        self::assertSame(self::decode($unknown), self::decode($malformed));
    }

    /** Quitter la guilde referme la porte : l'autorisation se relit, elle ne se mémorise pas. */
    public function testALeftGuildEndsTheVisibility(): void
    {
        [$founder, $member] = $this->guildOfTwo();

        self::assertSame(Response::HTTP_OK, $this->get('/api/players/'.$member->id->toRfc4122(), $founder->headers)->getStatusCode());

        $this->send('POST', '/api/guilds/mine/leave', null, $member->headers);

        self::assertSame(
            Response::HTTP_NOT_FOUND,
            $this->get('/api/players/'.$member->id->toRfc4122(), $founder->headers)->getStatusCode(),
        );
    }

    /**
     * **La revue du DTO fait partie du ticket.** Ce sont des données de compte, pas de
     * profil public, et elles ne doivent jamais franchir cette route.
     */
    public function testTheProfileNeverLeaksAccountData(): void
    {
        [$founder, $member] = $this->guildOfTwo();

        $body = self::decode($this->get('/api/players/'.$member->id->toRfc4122(), $founder->headers));

        self::assertArrayNotHasKey('email', $body);
        self::assertArrayNotHasKey('timezone', $body);
        self::assertArrayNotHasKey('roles', $body);
        self::assertArrayNotHasKey('password', $body);
        self::assertArrayNotHasKey('nextTitle', $body, 'Le prochain titre visé n\'a de sens que sur son propre profil.');
    }

    /** Même bloc que dans la liste des membres : mêmes ports, même ressource. */
    public function testTheProfileMatchesTheMemberBlockExactly(): void
    {
        [$founder, $member] = $this->guildOfTwo();

        $alone = self::decode($this->get('/api/players/'.$member->id->toRfc4122(), $founder->headers));

        $guild = self::decode($this->get('/api/guilds/mine', $founder->headers))['guild'];
        self::assertIsArray($guild);
        $members = $guild['members'];
        self::assertIsArray($members);

        $inList = null;
        foreach ($members as $candidate) {
            self::assertIsArray($candidate);
            if ($candidate['id'] === $member->id->toRfc4122()) {
                $inList = $candidate;
            }
        }

        self::assertIsArray($inList);

        // La liste ajoute le rôle et la date d'entrée ; tout le reste doit coïncider, sans
        // quoi le client aurait deux types à décoder pour le même objet.
        unset($inList['role'], $inList['joinedAt']);
        self::assertSame($alone, $inList);
    }

    public function testRefusesAnAnonymousCaller(): void
    {
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->get('/api/players/'.Uuid::v7()->toRfc4122())->getStatusCode());
    }

    /**
     * @return array{Account, Account}
     */
    private function guildOfTwo(): array
    {
        $founder = $this->openAccount();
        $member = $this->openAccount('carla@grrind.app', 'Carla');

        $created = $this->post('/api/guilds', ['name' => 'Les Lève-Tôt'], $founder->headers);
        self::assertSame(Response::HTTP_CREATED, $created->getStatusCode(), (string) $created->getContent());
        $guildId = self::decode($created)['id'];
        self::assertIsString($guildId);

        $issued = $this->post('/api/guilds/'.$guildId.'/invite-code', [], $founder->headers);
        self::assertSame(Response::HTTP_CREATED, $issued->getStatusCode());
        $code = self::decode($issued)['code'];
        self::assertIsString($code);

        self::assertSame(
            Response::HTTP_OK,
            $this->post('/api/guilds/join', ['code' => $code], $member->headers)->getStatusCode(),
        );

        return [$founder, $member];
    }
}
