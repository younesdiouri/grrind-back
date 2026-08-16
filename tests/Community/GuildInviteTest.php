<?php

declare(strict_types=1);

namespace App\Tests\Community;

use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Générer, révoquer, rejoindre — et surtout : **ce que la route refuse de dire**.
 *
 * Le test qui compte le plus est celui qui compare octet par octet la réponse d'un code
 * inconnu et celle d'un code expiré. Les vérifier séparément laisserait les deux chemins
 * diverger d'un champ, et la route redeviendrait un oracle qui dit quels codes existent.
 */
final class GuildInviteTest extends ApiTestCase
{
    public function testTheFounderIssuesACode(): void
    {
        $founder = $this->openAccount();
        $guildId = $this->foundGuild($founder);

        $response = $this->post('/api/guilds/'.$guildId.'/invite-code', [], $founder->headers);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);
        self::assertIsString($body['code']);
        self::assertSame(8, \strlen($body['code']));
        self::assertDoesNotMatchRegularExpression('/[O0IL1]/', $body['code']);
        self::assertIsString($body['expiresAt']);
    }

    /** Régénérer est le geste par lequel on coupe un code qui a trop circulé. */
    public function testIssuingANewCodeRevokesThePreviousOne(): void
    {
        $founder = $this->openAccount();
        $guildId = $this->foundGuild($founder);

        $first = $this->issueCode($founder, $guildId);
        $second = $this->issueCode($founder, $guildId);

        self::assertNotSame($first, $second);

        $newcomer = $this->openAccount('carla@grrind.app', 'Carla');
        self::assertSame(Response::HTTP_NOT_FOUND, $this->join($newcomer, $first)->getStatusCode());
        self::assertSame(Response::HTTP_OK, $this->join($newcomer, $second)->getStatusCode());
    }

    public function testAMemberWhoIsNotTheFounderCannotIssueACode(): void
    {
        [, $member, $guildId] = $this->guildOfTwo();

        $response = $this->post('/api/guilds/'.$guildId.'/invite-code', [], $member->headers);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testAStrangerGetsNotFoundRatherThanForbidden(): void
    {
        $guildId = $this->foundGuild($this->openAccount());
        $stranger = $this->openAccount('carla@grrind.app', 'Carla');

        $response = $this->post('/api/guilds/'.$guildId.'/invite-code', [], $stranger->headers);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testANewcomerJoinsWithTheCode(): void
    {
        $founder = $this->openAccount();
        $guildId = $this->foundGuild($founder);
        $code = $this->issueCode($founder, $guildId);

        $response = $this->join($this->openAccount('carla@grrind.app', 'Carla'), $code);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);
        self::assertSame($guildId, $body['id']);
        self::assertSame('MEMBER', $body['role']);
        self::assertSame(2, $body['memberCount']);
    }

    /** Le code se colle depuis un message : la casse et les espaces sont une question de saisie. */
    public function testTheCodeIsNormalisedBeforeItIsLookedUp(): void
    {
        $founder = $this->openAccount();
        $code = $this->issueCode($founder, $this->foundGuild($founder));

        $response = $this->join($this->openAccount('carla@grrind.app', 'Carla'), '  '.strtolower($code).' ');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    }

    public function testARevokedCodeOpensNothing(): void
    {
        $founder = $this->openAccount();
        $guildId = $this->foundGuild($founder);
        $code = $this->issueCode($founder, $guildId);

        self::assertSame(
            Response::HTTP_NO_CONTENT,
            $this->send('DELETE', '/api/guilds/'.$guildId.'/invite-code', null, $founder->headers)->getStatusCode(),
        );

        self::assertSame(Response::HTTP_NOT_FOUND, $this->join($this->openAccount('carla@grrind.app', 'Carla'), $code)->getStatusCode());
    }

    /** L'état visé est « aucun code vivant », et il est déjà atteint. */
    public function testRevokingWithNothingToRevokeIsNotAnError(): void
    {
        $founder = $this->openAccount();
        $guildId = $this->foundGuild($founder);

        $response = $this->send('DELETE', '/api/guilds/'.$guildId.'/invite-code', null, $founder->headers);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    }

    /** Révoquer ne doit pas empêcher d'en régénérer un : c'est ce que l'index partiel autorise. */
    public function testACodeCanBeIssuedAgainAfterARevocation(): void
    {
        $founder = $this->openAccount();
        $guildId = $this->foundGuild($founder);

        $this->issueCode($founder, $guildId);
        $this->send('DELETE', '/api/guilds/'.$guildId.'/invite-code', null, $founder->headers);
        $reissued = $this->issueCode($founder, $guildId);

        self::assertSame(Response::HTTP_OK, $this->join($this->openAccount('carla@grrind.app', 'Carla'), $reissued)->getStatusCode());
    }

    public function testAnExpiredCodeOpensNothing(): void
    {
        $founder = $this->openAccount();
        $code = $this->issueCode($founder, $this->foundGuild($founder));

        $this->expire($code);

        self::assertSame(Response::HTTP_NOT_FOUND, $this->join($this->openAccount('carla@grrind.app', 'Carla'), $code)->getStatusCode());
    }

    /**
     * **Le test qui porte la décision du ticket.** Un code expiré et un code qui n'a jamais
     * existé doivent produire la même réponse au champ près : les distinguer dirait quels
     * codes ont existé, donc lesquels retenter après une régénération.
     */
    public function testAnExpiredCodeIsIndistinguishableFromAnUnknownOne(): void
    {
        $founder = $this->openAccount();
        $code = $this->issueCode($founder, $this->foundGuild($founder));
        $this->expire($code);

        $newcomer = $this->openAccount('carla@grrind.app', 'Carla');

        $expired = $this->join($newcomer, $code);
        $unknown = $this->join($newcomer, 'ZZZZZZZZ');

        self::assertSame($expired->getStatusCode(), $unknown->getStatusCode());
        self::assertSame(self::decode($expired), self::decode($unknown));
        self::assertSame('https://grrind.app/problems/invite-code-not-usable', self::decode($expired)['type']);
    }

    /** Et le troisième cas : révoqué, indistinguable des deux autres. */
    public function testARevokedCodeIsIndistinguishableToo(): void
    {
        $founder = $this->openAccount();
        $guildId = $this->foundGuild($founder);
        $code = $this->issueCode($founder, $guildId);
        $this->send('DELETE', '/api/guilds/'.$guildId.'/invite-code', null, $founder->headers);

        $newcomer = $this->openAccount('carla@grrind.app', 'Carla');

        self::assertSame(self::decode($this->join($newcomer, 'ZZZZZZZZ')), self::decode($this->join($newcomer, $code)));
    }

    public function testAPlayerAlreadyInAGuildCannotJoinAnother(): void
    {
        $founder = $this->openAccount();
        $code = $this->issueCode($founder, $this->foundGuild($founder));

        $other = $this->openAccount('carla@grrind.app', 'Carla');
        $this->post('/api/guilds', ['name' => 'La sienne'], $other->headers);

        $response = $this->join($other, $code);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame('https://grrind.app/problems/player-already-in-a-guild', self::decode($response)['type']);
    }

    /**
     * L'ordre des vérifications est une décision : l'appartenance passe **avant** le code,
     * pour qu'un joueur déjà en guilde ne puisse pas se servir de la route pour trier les
     * codes valides. Les deux réponses doivent donc être identiques.
     */
    public function testAPlayerInAGuildLearnsNothingAboutTheCodeHeSubmits(): void
    {
        $founder = $this->openAccount();
        $valid = $this->issueCode($founder, $this->foundGuild($founder));

        $other = $this->openAccount('carla@grrind.app', 'Carla');
        $this->post('/api/guilds', ['name' => 'La sienne'], $other->headers);

        self::assertSame(self::decode($this->join($other, $valid)), self::decode($this->join($other, 'ZZZZZZZZ')));
    }

    public function testAFullGuildRefusesTheNewcomer(): void
    {
        $founder = $this->openAccount();
        $guildId = $this->foundGuild($founder);
        $code = $this->issueCode($founder, $guildId);

        // Vingt-neuf places de plus que le fondateur : la guilde est pleine.
        $this->fillUpTo($guildId, 30);

        $response = $this->join($this->openAccount('carla@grrind.app', 'Carla'), $code);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);
        self::assertSame('https://grrind.app/problems/guild-is-full', $body['type']);
        self::assertSame(30, $body['capacity'], 'Le client écrit la capacité dans sa phrase, il ne la devine pas.');
    }

    /**
     * **La dernière place, prise deux fois.** Le client HTTP de test est séquentiel : deux
     * requêtes vraiment simultanées ne se jouent pas ici. Ce qui se démontre est l'invariant
     * qui compte — quel que soit le nombre de tentatives sur une place unique, une seule
     * aboutit et la guilde ne dépasse jamais son plafond.
     *
     * La partie que le test ne peut pas jouer est tenue par le verrou de ligne pris par
     * `JoinGuildHandler` **avant** de charger les adhésions : sans lui, les deux requêtes
     * compteraient les mêmes 29 membres et écriraient toutes les deux.
     */
    public function testOnlyOneNewcomerTakesTheLastSeat(): void
    {
        $founder = $this->openAccount();
        $guildId = $this->foundGuild($founder);
        $code = $this->issueCode($founder, $guildId);

        $this->fillUpTo($guildId, 29);

        $first = $this->join($this->openAccount('carla@grrind.app', 'Carla'), $code);
        $second = $this->join($this->openAccount('dan@grrind.app', 'Dan'), $code);

        self::assertSame(Response::HTTP_OK, $first->getStatusCode(), (string) $first->getContent());
        self::assertSame(30, self::decode($first)['memberCount']);

        self::assertSame(Response::HTTP_CONFLICT, $second->getStatusCode());
        self::assertSame('https://grrind.app/problems/guild-is-full', self::decode($second)['type']);
        self::assertSame(30, $this->countMembers($guildId), 'Le plafond ne se dépasse pas, même d\'un.');
    }

    public function testRefusesAMalformedCodeWithoutSpendingALookup(): void
    {
        $response = $this->join($this->openAccount(), 'TROPCOURT1234');

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function testRefusesAnAnonymousJoin(): void
    {
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->post('/api/guilds/join', ['code' => 'ABCDEFGH'])->getStatusCode());
    }

    /**
     * Le limiteur est par joueur : au onzième essai dans le quart d'heure, la route se
     * ferme. C'est ce qui met le tirage au hasard hors de portée — huit caractères sur
     * trente et un ne se devinent qu'à la boucle.
     */
    public function testGuessingCodesIsRateLimitedPerPlayer(): void
    {
        $guesser = $this->openAccount();

        for ($attempt = 0; $attempt < 10; ++$attempt) {
            self::assertSame(Response::HTTP_NOT_FOUND, $this->join($guesser, 'ZZZZZZZZ')->getStatusCode());
        }

        $blocked = $this->join($guesser, 'ZZZZZZZZ');

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $blocked->getStatusCode());
        self::assertTrue($blocked->headers->has('Retry-After'), 'Le client doit savoir combien attendre plutôt que de boucler.');
    }

    /** Un joueur qui sature son quota ne doit pas fermer la route aux autres. */
    public function testTheLimitDoesNotSpillOntoAnotherPlayer(): void
    {
        $guesser = $this->openAccount();

        for ($attempt = 0; $attempt <= 10; ++$attempt) {
            $this->join($guesser, 'ZZZZZZZZ');
        }

        $founder = $this->openAccount('carla@grrind.app', 'Carla');
        $code = $this->issueCode($founder, $this->foundGuild($founder));

        $innocent = $this->openAccount('dan@grrind.app', 'Dan');

        self::assertSame(Response::HTTP_OK, $this->join($innocent, $code)->getStatusCode());
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

    /** Antidate l'expiration : attendre quarante-huit heures n'est pas une option. */
    private function expire(string $code): void
    {
        self::connection()->executeStatement(
            "UPDATE community_guild_invite_code SET expires_at = NOW() - INTERVAL '1 hour' WHERE code = ?",
            [$code],
        );
    }

    /**
     * Remplit la guilde jusqu'à `$size` membres. Les joueurs sont posés en base plutôt
     * qu'inscrits par HTTP : ouvrir vingt-neuf comptes coûterait vingt-neuf hachages de
     * mot de passe pour démontrer un plafond qui ne les regarde pas.
     */
    private function fillUpTo(string $guildId, int $size): void
    {
        $connection = self::connection();

        for ($seat = $this->countMembers($guildId); $seat < $size; ++$seat) {
            $connection->executeStatement(
                'INSERT INTO community_guild_membership (id, guild_id, player_id, role, joined_at) VALUES (?, ?, ?, ?, NOW())',
                [Uuid::v7()->toRfc4122(), $guildId, Uuid::v7()->toRfc4122(), 'MEMBER'],
            );
        }
    }

    private function countMembers(string $guildId): int
    {
        $count = self::connection()->fetchOne('SELECT COUNT(*) FROM community_guild_membership WHERE guild_id = ?', [$guildId]);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    /**
     * @return array{Account, Account, string}
     */
    private function guildOfTwo(): array
    {
        $founder = $this->openAccount();
        $member = $this->openAccount('carla@grrind.app', 'Carla');
        $guildId = $this->foundGuild($founder);
        $code = $this->issueCode($founder, $guildId);

        self::assertSame(Response::HTTP_OK, $this->join($member, $code)->getStatusCode());

        return [$founder, $member, $guildId];
    }

    private static function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
