<?php

declare(strict_types=1);

namespace App\Tests\Combat;

use App\Combat\Domain\Battle;
use App\Combat\Domain\BattleFinished;
use App\Combat\Domain\BattleOutcome;
use App\Combat\Domain\BattleResult;
use App\Combat\Domain\BattleStarted;
use App\Combat\Domain\Enemy;
use App\Combat\Domain\Fighter;
use App\Combat\Infrastructure\Doctrine\BattleRepository;
use App\Shared\Domain\Activity\AttributeGains;
use App\Shared\UI\Http\IdempotencyListener;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Battles;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /api/battles` et `GET /api/battles/{id}` — la porte du combat PvE.
 *
 * Le combat lui-même (#208-#211) est déjà prouvé sans HTTP ; ce qui se joue ici est le
 * contrat : la timeline complète en un seul aller-retour, l'idempotence d'un tirage qui ne
 * se rattrape pas, et le 404 qui ne dit jamais qu'un combat existe.
 */
final class BattlesTest extends ApiTestCase
{
    use Battles;

    public function testFightingCreatesABattleAndRendersItsTimeline(): void
    {
        $bob = $this->openAccount();

        $response = $this->fight($bob);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);
        self::assertIsString($body['id']);
        self::assertContains($body['result'], ['VICTORY', 'DEFEAT']);
        self::assertIsInt($body['turns']);
        self::assertGreaterThan(0, $body['turns']);
        self::assertIsString($body['foughtAt']);

        $player = $body['player'];
        self::assertIsArray($player);
        self::assertArrayHasKey('hp', $player);
        self::assertArrayHasKey('damage', $player);
        self::assertArrayHasKey('mitigationPercent', $player);
        self::assertArrayHasKey('extraTurnPercent', $player);
        self::assertArrayHasKey('dodgePercent', $player);

        // Un compte neuf est niveau 1 : `EnemyCatalog::forLevel(1)` rend toujours SAND_JACKAL
        // (`combat.yaml`) — ça prouve que le contrôleur ne choisit rien lui-même.
        $enemy = $body['enemy'];
        self::assertIsArray($enemy);
        self::assertSame('SAND_JACKAL', $enemy['key']);
        self::assertIsString($enemy['name']);
        self::assertNotSame('', $enemy['name'], 'Le nom doit arriver traduit, jamais la clé brute.');

        $events = $body['events'];
        self::assertIsArray($events);
        self::assertNotEmpty($events);

        $first = $events[0];
        self::assertIsArray($first);
        self::assertSame('BATTLE_STARTED', $first['type']);

        $last = $events[array_key_last($events)];
        self::assertIsArray($last);
        self::assertSame('BATTLE_FINISHED', $last['type']);
        self::assertSame($body['result'], $last['result']);

        // Toujours la forme complète, victoire ou défaite — voir le docblock de
        // `App\Shared\Application\BattleDrop::none()` : jamais une clé absente.
        $rewards = $body['rewards'];
        self::assertIsArray($rewards);
        self::assertIsArray($rewards['loot']);
        $coins = $rewards['coins'];
        self::assertIsArray($coins);
        self::assertArrayHasKey('gained', $coins);
        self::assertArrayHasKey('before', $coins);
        self::assertArrayHasKey('after', $coins);

        // Seule une victoire rapporte — voir le docblock de `FightBattleHandler`.
        if ('DEFEAT' === $body['result']) {
            self::assertSame([], $rewards['loot']);
            self::assertSame(0, $coins['gained']);
            self::assertSame($coins['before'], $coins['after']);
        }
    }

    public function testAHotBattleOnlyReadsTheRulesetRevisionPointer(): void
    {
        $bob = $this->openAccount('battle-hot@grrind.app');
        $this->client->disableReboot();
        $this->fight($bob, 'battle-warmup');

        $this->client->enableProfiler();
        self::assertSame(Response::HTTP_CREATED, $this->fight($bob, 'battle-hot')->getStatusCode());
        $this->assertOnlyRulesetRevisionPointerSql();
    }

    /**
     * Le rejeu d'une requête dont la réponse s'est perdue rend le **même** combat, jamais un
     * second : un tirage aléatoire ne se rattrape pas, contrairement à un import.
     */
    public function testReplayingTheSameKeyGivesBackTheSameBattle(): void
    {
        $bob = $this->openAccount();

        $first = $this->fight($bob);
        $replay = $this->fight($bob);

        self::assertSame('true', $replay->headers->get(IdempotencyListener::REPLAY_HEADER));
        self::assertSame($first->getContent(), $replay->getContent());
    }

    public function testTwoDistinctKeysProduceTwoDistinctBattles(): void
    {
        $bob = $this->openAccount();

        $first = self::decode($this->fight($bob, 'premier-combat'));
        $second = self::decode($this->fight($bob, 'second-combat'));

        self::assertNotSame($first['id'], $second['id']);
    }

    public function testFightingWithoutAnIdempotencyKeyIsRefused(): void
    {
        $bob = $this->openAccount();

        $response = $this->post('/api/battles', [], $bob->headers);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testFightingWithoutATokenIsRefused(): void
    {
        $response = $this->post('/api/battles', [], ['Idempotency-Key' => 'sans-jeton']);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testAPlayerCanReplayHisOwnBattle(): void
    {
        $bob = $this->openAccount();
        $fought = self::decode($this->fight($bob));
        self::assertIsString($fought['id']);

        $response = $this->get('/api/battles/'.$fought['id'], $bob->headers);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        // `assertEquals`, pas `assertSame` : PostgreSQL réordonne les clés d'un objet JSONB
        // (par longueur puis ordre alphabétique) au stockage — un événement relu en base ne
        // porte pas forcément ses champs dans l'ordre où `Battle::eventToArray()` les a
        // écrits. Seul l'ordre des **éléments de la liste** `events` compte, et celui-là
        // survit — voir le docblock de `Battle`.
        self::assertEquals($fought, self::decode($response));
    }

    public function testAnOldPersistedLootRewardGetsAnAbsoluteReachableImageUrl(): void
    {
        $bob = $this->openAccount();
        $id = $this->recordBattle(
            $bob,
            new DateTimeImmutable('2026-07-15T08:00:00+00:00'),
            reward: ['loot' => [['key' => 'WORN_RUNNING_SHOES']], 'coins' => ['gained' => 8, 'before' => 40, 'after' => 48]],
        );

        $response = $this->get('/api/battles/'.$id, $bob->headers);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $body = self::decode($response);
        self::assertIsArray($body['rewards']);
        self::assertIsArray($body['rewards']['loot']);
        $loot = $body['rewards']['loot'][0];
        self::assertIsArray($loot);
        self::assertIsString($loot['imageUrl']);
        self::assertMatchesRegularExpression('#^http://localhost/game-images/[a-z0-9._-]+$#', $loot['imageUrl']);

        $path = parse_url($loot['imageUrl'], \PHP_URL_PATH);
        self::assertIsString($path);
        self::assertSame(Response::HTTP_OK, $this->get($path)->getStatusCode());
    }

    /**
     * Le test qui porte la décision du ticket : un combat qui ne m'appartient pas et un UUID
     * qui ne désigne personne doivent rendre la même réponse au champ près. Les vérifier
     * séparément laisserait les deux chemins diverger, et la route redeviendrait un oracle.
     */
    public function testAStrangerAndAnUnknownUuidAreIndistinguishable(): void
    {
        $bob = $this->openAccount();
        $alice = $this->openAccount('alice@grrind.app', 'Alice');
        $fought = self::decode($this->fight($bob));
        self::assertIsString($fought['id']);

        $forbidden = $this->get('/api/battles/'.$fought['id'], $alice->headers);
        $unknown = $this->get('/api/battles/'.Uuid::v7()->toRfc4122(), $alice->headers);

        self::assertSame(Response::HTTP_NOT_FOUND, $forbidden->getStatusCode());
        self::assertSame($forbidden->getStatusCode(), $unknown->getStatusCode());
        self::assertSame(self::decode($forbidden), self::decode($unknown));
        self::assertSame('https://grrind.app/problems/battle-not-found', self::decode($forbidden)['type']);
        self::assertNotSame(Response::HTTP_FORBIDDEN, $forbidden->getStatusCode(), 'Un 403 confirmerait qu\'un combat porte cet UUID.');
    }

    public function testAMalformedIdentifierGetsTheSameAnswer(): void
    {
        $bob = $this->openAccount();

        $malformed = $this->get('/api/battles/pas-un-uuid', $bob->headers);
        $unknown = $this->get('/api/battles/'.Uuid::v7()->toRfc4122(), $bob->headers);

        self::assertSame(Response::HTTP_NOT_FOUND, $malformed->getStatusCode());
        self::assertSame(self::decode($unknown), self::decode($malformed));
    }

    /**
     * Le catalogue est du config-as-code, fait pour être édité — `combat.yaml` annonce
     * lui-même que des paliers s'ajouteront. Un combat déjà joué est un fait écrit : sa
     * lecture ne doit rien à l'état *courant* du catalogue, voir le docblock de
     * `BattleResource`. Retirer ou renommer une entrée ne doit donc jamais faire tomber
     * cette route.
     */
    public function testABattleAgainstAnEnemyNoLongerInTheCatalogueStillRenders200(): void
    {
        $bob = $this->openAccount();

        $repository = self::getContainer()->get(BattleRepository::class);
        self::assertInstanceOf(BattleRepository::class, $repository);

        $battle = Battle::conclude(
            Uuid::v7(),
            $bob->id,
            new AttributeGains(0, 0, 0, 0),
            0,
            new Fighter(140, 16, 0, 0, 0),
            new Enemy('GHOST_ENEMY', 1, 120, 12, 50, 40, 30),
            new Fighter(120, 12, 50, 40, 30),
            new BattleOutcome(
                BattleResult::Victory,
                [new BattleStarted(140, 120), new BattleFinished(BattleResult::Victory)],
                1,
            ),
            ['loot' => [], 'coins' => ['gained' => 0, 'before' => 0, 'after' => 0]],
            random_bytes(32),
            'v1-000000000000',
            new DateTimeImmutable(),
        );

        $repository->add($battle);
        $repository->commit();

        $response = $this->get('/api/battles/'.$battle->id()->toRfc4122(), $bob->headers);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $enemy = self::decode($response)['enemy'];
        self::assertIsArray($enemy);
        self::assertSame('GHOST_ENEMY', $enemy['key']);
        // Dégradé et lisible plutôt qu'un écran mort : le vrai traducteur Symfony rend l'id
        // tel quel quand la clé manque à translations/enemies.*.yaml.
        self::assertSame('ghost_enemy.name', $enemy['name']);
    }

    public function testShowingABattleRefusesAnAnonymousCaller(): void
    {
        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $this->get('/api/battles/'.Uuid::v7()->toRfc4122())->getStatusCode(),
        );
    }

    /**
     * Le corps accepte n'importe quelle clé du catalogue, pas seulement les boss — voir le
     * docblock de `FightBattleHandler`. Un compte neuf (niveau 1) qui nomme `SAND_JACKAL`
     * (`level: 1`) obtient exactement ce que le choix automatique lui aurait donné, mais en
     * le demandant explicitement.
     */
    public function testChoosingAKnownOrdinaryEnemyByKeyFightsIt(): void
    {
        $bob = $this->openAccount();

        $response = $this->post('/api/battles', ['enemy' => 'SAND_JACKAL'], $bob->headers + ['Idempotency-Key' => 'contre-le-chacal']);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());
        $enemy = self::decode($response)['enemy'];
        self::assertIsArray($enemy);
        self::assertSame('SAND_JACKAL', $enemy['key']);
    }

    /**
     * Une clé qui ne désigne ni un ennemi ni un boss est un 422, pas un 404 : le catalogue
     * est public par `GET /api/enemies`, il n'y a rien à cacher.
     */
    public function testChoosingAnUnknownEnemyKeyIsRefused(): void
    {
        $bob = $this->openAccount();

        $response = $this->post('/api/battles', ['enemy' => 'GHOST_OF_A_KEY'], $bob->headers + ['Idempotency-Key' => 'clef-inconnue']);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('https://grrind.app/problems/enemy-key-unknown', self::decode($response)['type']);
    }

    /**
     * Un compte neuf (niveau 1) qui nomme un boss dont le `minimum_level` n'est pas atteint
     * reçoit un 422 dédié — et surtout, aucune ligne n'est écrite : le refus tombe avant que
     * le combat ne soit joué, voir le docblock de `FightBattleHandler`.
     */
    public function testChoosingAnEnemyBelowTheRequiredLevelIsRefusedAndWritesNothing(): void
    {
        $bob = $this->openAccount();

        $response = $this->post('/api/battles', ['enemy' => 'DUNE_SOVEREIGN'], $bob->headers + ['Idempotency-Key' => 'trop-tot']);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('https://grrind.app/problems/enemy-level-too-low', self::decode($response)['type']);

        $repository = self::getContainer()->get(BattleRepository::class);
        self::assertInstanceOf(BattleRepository::class, $repository);
        self::assertSame(0, $repository->count([]), 'Le refus de niveau ne doit laisser aucun combat écrit.');
    }

    private function fight(Account $account, string $key = 'combat-du-jour'): Response
    {
        return $this->post('/api/battles', [], $account->headers + ['Idempotency-Key' => $key]);
    }
}
