<?php

declare(strict_types=1);

namespace App\Tests\Combat;

use App\Shared\UI\Http\IdempotencyListener;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /api/players/{id}/battles` — défier un co-équipier (#283).
 *
 * Le combat lui-même est déjà prouvé sans HTTP (#208-#211) et le moteur n'a pas bougé d'une
 * ligne pour le PvP. Ce qui se joue ici est donc le contrat et rien d'autre : la même charge
 * utile qu'un combat PvE — pour que le client l'anime sans une branche —, l'idempotence d'un
 * tirage qui ne se rattrape pas, et surtout le 404 qui ne dit jamais qu'un compte porte cet
 * UUID.
 */
final class DuelsTest extends ApiTestCase
{
    public function testChallengingATeammateRendersABattleAgainstHim(): void
    {
        [$bob, $carla] = $this->guildOfTwo();

        $response = $this->challenge($bob, $carla->id->toRfc4122());

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);
        $enemy = $body['enemy'];
        self::assertIsArray($enemy);

        // Le couple qui distingue les deux formes — voir le docblock de `BattleResource`.
        self::assertNull($enemy['key']);
        self::assertSame($carla->id->toRfc4122(), $enemy['playerId']);
        self::assertSame('Carla', $enemy['name']);
        self::assertNull($enemy['imageUrls']);
        self::assertNull($enemy['introduction']);

        // Tout le reste est un combat comme les autres : c'est ce qui permet au client de
        // réutiliser son écran d'animation tel quel.
        self::assertContains($body['result'], ['VICTORY', 'DEFEAT']);
        $events = $body['events'];
        self::assertIsArray($events);
        $first = $events[0];
        $last = $events[array_key_last($events)];
        self::assertIsArray($first);
        self::assertIsArray($last);
        self::assertSame('BATTLE_STARTED', $first['type']);
        self::assertSame('BATTLE_FINISHED', $last['type']);

        // Aucune récompense en v1, et la forme reste complète — voir le docblock de
        // `ChallengePlayerHandler`.
        self::assertSame(['loot' => [], 'coins' => ['gained' => 0, 'before' => 0, 'after' => 0]], $body['rewards']);
    }

    /** Le défi entre dans l'historique du **défieur**, et se rejoue comme un combat PvE. */
    public function testADuelIsReplayableByTheChallengerAndListedInHisHistory(): void
    {
        [$bob, $carla] = $this->guildOfTwo();

        $fought = self::decode($this->challenge($bob, $carla->id->toRfc4122()));
        self::assertIsString($fought['id']);

        $replayed = $this->get('/api/battles/'.$fought['id'], $bob->headers);
        self::assertSame(Response::HTTP_OK, $replayed->getStatusCode());
        self::assertEquals($fought, self::decode($replayed));

        $history = self::decode($this->get('/api/battles', $bob->headers));
        $battles = $history['battles'];
        self::assertIsArray($battles);
        self::assertCount(1, $battles);
        $line = $battles[0];
        self::assertIsArray($line);
        $enemy = $line['enemy'];
        self::assertIsArray($enemy);
        self::assertNull($enemy['key']);
        self::assertSame($carla->id->toRfc4122(), $enemy['playerId']);
        self::assertSame('Carla', $enemy['name']);
    }

    /**
     * Le défié ne voit rien — décision du #283 : on livre un simulateur d'affrontement, pas
     * un duel à deux consentements. Le jour où le PvP aura une finalité, c'est ce test qui
     * devra changer d'avis explicitement.
     */
    public function testTheChallengedPlayerSeesNothing(): void
    {
        [$bob, $carla] = $this->guildOfTwo();

        $fought = self::decode($this->challenge($bob, $carla->id->toRfc4122()));
        self::assertIsString($fought['id']);

        $history = self::decode($this->get('/api/battles', $carla->headers));
        self::assertSame([], $history['battles']);

        // Et le rejeu lui est refusé comme n'importe quel combat qu'il n'a pas mené.
        self::assertSame(
            Response::HTTP_NOT_FOUND,
            $this->get('/api/battles/'.$fought['id'], $carla->headers)->getStatusCode(),
        );
    }

    /**
     * Le test qui porte la décision : un joueur d'une autre guilde, un joueur sans guilde, un
     * UUID qui ne désigne personne et un identifiant malformé rendent **la même** réponse.
     * Les vérifier séparément laisserait les quatre chemins diverger, et la route deviendrait
     * un oracle d'existence sur des UUID v7 — qui se devinent par plage temporelle.
     */
    public function testAStrangerAnUnknownUuidAndAMalformedIdAreIndistinguishable(): void
    {
        [$bob] = $this->guildOfTwo();
        $loner = $this->openAccount('dave@grrind.app', 'Dave');

        $refusals = [
            'un joueur sans guilde' => $this->challenge($bob, $loner->id->toRfc4122(), 'defi-etranger'),
            'un UUID inconnu' => $this->challenge($bob, Uuid::v7()->toRfc4122(), 'defi-inconnu'),
            'un identifiant malformé' => $this->challenge($bob, 'pas-un-uuid', 'defi-malforme'),
        ];

        foreach ($refusals as $what => $response) {
            self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), $what);
            $body = self::decode($response);
            self::assertSame('https://grrind.app/problems/opponent-not-found', $body['type'], $what);
        }
    }

    /**
     * Se défier soi-même est un 422 et non un 404 : l'UUID est celui de l'appelant, il n'y a
     * rien à cacher sur son existence. Le cas vaut **sans guilde** aussi — c'est pour ça que
     * le contrôle passe avant celui du co-équipier, voir le docblock du handler.
     */
    public function testChallengingYourselfIsRefusedEvenWithoutAGuild(): void
    {
        $loner = $this->openAccount('eve@grrind.app', 'Eve');

        $response = $this->challenge($loner, $loner->id->toRfc4122());

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('https://grrind.app/problems/cannot-challenge-yourself', self::decode($response)['type']);
    }

    /**
     * Un rejeu réseau doit rendre **ce** défi, jamais en tirer un second : un combat est un
     * tirage aléatoire sans unicité naturelle, et c'est le seul endroit où la clé
     * d'idempotence n'a aucun filet derrière elle.
     */
    public function testReplayingTheSameKeyGivesBackTheSameDuel(): void
    {
        [$bob, $carla] = $this->guildOfTwo();

        $first = $this->challenge($bob, $carla->id->toRfc4122());
        $replay = $this->challenge($bob, $carla->id->toRfc4122());

        self::assertSame('true', $replay->headers->get(IdempotencyListener::REPLAY_HEADER));
        self::assertSame($first->getContent(), $replay->getContent());
    }

    public function testChallengingWithoutAnIdempotencyKeyIsRefused(): void
    {
        [$bob, $carla] = $this->guildOfTwo();

        $response = $this->post('/api/players/'.$carla->id->toRfc4122().'/battles', [], $bob->headers);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testChallengingWithoutATokenIsRefused(): void
    {
        $response = $this->post('/api/players/'.Uuid::v7()->toRfc4122().'/battles', [], ['Idempotency-Key' => 'sans-jeton']);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    private function challenge(Account $account, string $opponentId, string $key = 'defi-du-jour'): HttpResponse
    {
        return $this->post('/api/players/'.$opponentId.'/battles', [], $account->headers + ['Idempotency-Key' => $key]);
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
