<?php

declare(strict_types=1);

namespace App\Tests\Rewards;

use App\Shared\Application\BattleDrops;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Le drop de combat (#227) contre la vraie implémentation `AdversaryBattleDrops` — le
 * jumeau de `App\Tests\Training\ImportLootTest` pour le combat plutôt que la séance. Un
 * vrai `random_bytes(32)` ici aussi : ce qui se prouve, ce sont les invariants qui tiennent
 * quel que soit le tirage, jamais un objet ou un montant précis.
 *
 * Appelé directement sur le port plutôt qu'au travers de `POST /api/battles` : le verdict
 * de victoire vient du simulateur, non maîtrisable depuis un test HTTP sans grainer le
 * combat lui-même — voir `FightBattleHandlerTest` pour pourquoi ce module ne le fait pas
 * non plus. `$victory` est ici un booléen explicite, exactement ce que
 * `FightBattleHandler` calcule avant d'appeler ce port.
 */
final class BattleLootTest extends ApiTestCase
{
    private BattleDrops $drops;

    protected function setUp(): void
    {
        parent::setUp();

        $drops = self::getContainer()->get(BattleDrops::class);
        self::assertInstanceOf(BattleDrops::class, $drops);
        $this->drops = $drops;
    }

    /**
     * Seule une victoire tire — voir le docblock de `FightBattleHandler`. Aucune ligne
     * d'audit, aucun crédit, et le solde rendu est le vrai solde du joueur (zéro, ici,
     * puisqu'il n'a encore rien reçu).
     */
    public function testADefeatRollsNothingAndPortsTheRealBalance(): void
    {
        $playerId = Uuid::v7();
        $battleId = Uuid::v7();

        $drop = $this->drops->rollFor($playerId, 'SAND_JACKAL', false, $battleId, new DateTimeImmutable('2026-08-30T09:00:00+00:00'));

        self::assertSame([], $drop->items);
        self::assertSame(0, $drop->coinsGained);
        self::assertSame(0, $drop->coinsBefore);
        self::assertSame(0, $drop->coinsAfter);

        self::assertSame(0, $this->countRollsFor($playerId));
        self::assertSame(0, $this->countCoinTransactionsFor($playerId));
    }

    /**
     * Une victoire écrit la ligne d'audit rattachée **au combat** (`cause_id`), crédite une
     * bande de pièces cohérente avec `loot.yaml` (2 à 8 pour `SAND_JACKAL`, toujours non
     * nulle), et rend un avant/après lu sur le vrai solde.
     */
    public function testAVictoryRollsTheEligibleTableAndWritesTheAuditTrail(): void
    {
        $playerId = Uuid::v7();
        $battleId = Uuid::v7();

        $drop = $this->drops->rollFor($playerId, 'SAND_JACKAL', true, $battleId, new DateTimeImmutable('2026-08-30T09:00:00+00:00'));

        $rolls = $this->connection()->fetchAllAssociative(
            'SELECT origin, table_key, cause_id FROM rewards_loot_roll WHERE user_id = :id',
            ['id' => $playerId->toRfc4122()],
        );
        self::assertCount(1, $rolls);
        self::assertSame('BATTLE', $rolls[0]['origin']);
        self::assertSame('SAND_JACKAL', $rolls[0]['table_key']);
        self::assertSame($battleId->toRfc4122(), $rolls[0]['cause_id']);

        // La bande de `SAND_JACKAL` dans `loot.yaml` : toujours entre 2 et 8 pièces, jamais
        // zéro — voir le docblock de `LootRoller` pour pourquoi les pièces se tirent
        // indépendamment de l'objet.
        self::assertGreaterThanOrEqual(2, $drop->coinsGained);
        self::assertLessThanOrEqual(8, $drop->coinsGained);
        self::assertSame(0, $drop->coinsBefore);
        self::assertSame($drop->coinsGained, $drop->coinsAfter);

        $coinRows = $this->connection()->fetchAllAssociative(
            'SELECT amount, reason FROM rewards_coin_transaction WHERE user_id = :id',
            ['id' => $playerId->toRfc4122()],
        );
        self::assertCount(1, $coinRows);
        self::assertSame('BATTLE_DROP', $coinRows[0]['reason']);
        self::assertIsNumeric($coinRows[0]['amount']);
        self::assertSame($drop->coinsGained, (int) $coinRows[0]['amount']);

        self::assertLessThanOrEqual(1, \count($drop->items), 'Un seul tirage : au plus un objet.');

        if ([] !== $drop->items) {
            self::assertContains($drop->items[0]->key, ['WORN_RUNNING_SHOES', 'IRON_GAUNTLETS']);

            $inventory = $this->connection()->fetchAssociative(
                'SELECT item_key, quantity FROM rewards_inventory_item WHERE user_id = :id',
                ['id' => $playerId->toRfc4122()],
            );
            self::assertIsArray($inventory);
            self::assertSame($drop->items[0]->key, $inventory['item_key']);
        }
    }

    /**
     * Un adversaire quelconque sans table dédiée dans `loot.yaml` ne tire rien — même
     * comportement qu'une défaite, voir le docblock de `LootRoller::rollForAdversary()`.
     */
    public function testAVictoryAgainstAnAdversaryWithoutADedicatedTableRollsNothing(): void
    {
        $playerId = Uuid::v7();
        $battleId = Uuid::v7();

        $drop = $this->drops->rollFor($playerId, 'GHOST_ENEMY', true, $battleId, new DateTimeImmutable('2026-08-30T09:00:00+00:00'));

        self::assertSame([], $drop->items);
        self::assertSame(0, $drop->coinsGained);
        self::assertSame(0, $this->countRollsFor($playerId));
    }

    private function countRollsFor(Uuid $playerId): int
    {
        $count = $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM rewards_loot_roll WHERE user_id = :id',
            ['id' => $playerId->toRfc4122()],
        );
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function countCoinTransactionsFor(Uuid $playerId): int
    {
        $count = $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM rewards_coin_transaction WHERE user_id = :id',
            ['id' => $playerId->toRfc4122()],
        );
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
