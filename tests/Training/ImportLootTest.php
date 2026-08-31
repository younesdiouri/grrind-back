<?php

declare(strict_types=1);

namespace App\Tests\Training;

use App\Progression\Application\GrantXp;
use App\Progression\Application\GrantXpHandler;
use App\Shared\Domain\Activity\Discipline;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Workouts;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Le loot et les pièces d'une séance créditée (#226), contre la vraie transaction d'import.
 *
 * Le tirage est un vrai `random_bytes(32)` ici, jamais grainé pour le test : ce que ces
 * suites prouvent, ce sont les invariants qui tiennent quel que soit le tirage — la table
 * choisie, le nombre de lignes écrites, les bornes de la bande de pièces — jamais un objet
 * ou un montant précis. {@see \App\Tests\Combat\Application\FightBattleHandlerTest} fait le
 * même choix pour la même raison sur `random_bytes(32)` d'un combat.
 */
final class ImportLootTest extends ApiTestCase
{
    use Workouts;

    private GrantXpHandler $grantXp;

    protected function setUp(): void
    {
        parent::setUp();

        $grantXp = self::getContainer()->get(GrantXpHandler::class);
        self::assertInstanceOf(GrantXpHandler::class, $grantXp);
        $this->grantXp = $grantXp;
    }

    /**
     * Le cas nominal : une séance créditée, au-dessus des seuils de `STARTER_SESSION_DROP`
     * (niveau 1, 20 minutes) et sous ceux de `TRAINED_SESSION_DROP` (niveau 5), tire sur
     * cette seule table.
     */
    public function testACreditedWorkoutRollsTheEligibleTableAndWritesTheAuditTrail(): void
    {
        $bob = $this->openAccount();

        $body = self::decode($this->import($bob, [self::candidate(durationSeconds: 2700)]));

        self::assertIsArray($body['imported']);
        $reward = $body['imported'][0];
        self::assertIsArray($reward);

        // La ligne d'audit du tirage : une par workout crédité, jamais par synchronisation.
        $rolls = $this->connection()->fetchAllAssociative(
            'SELECT origin, table_key, cause_id FROM rewards_loot_roll WHERE user_id = :id',
            ['id' => $bob->id->toRfc4122()],
        );
        self::assertCount(1, $rolls);
        self::assertSame('WORKOUT', $rolls[0]['origin']);
        self::assertSame('STARTER_SESSION_DROP', $rolls[0]['table_key']);

        // Une bande de 5 à 15 pièces pour `STARTER_SESSION_DROP` — voir `loot.yaml`.
        $coinRows = $this->connection()->fetchAllAssociative(
            'SELECT amount, reason FROM rewards_coin_transaction WHERE user_id = :id',
            ['id' => $bob->id->toRfc4122()],
        );
        self::assertCount(1, $coinRows);
        self::assertSame('WORKOUT_DROP', $coinRows[0]['reason']);
        self::assertIsNumeric($coinRows[0]['amount']);
        $amount = (int) $coinRows[0]['amount'];
        self::assertGreaterThanOrEqual(5, $amount);
        self::assertLessThanOrEqual(15, $amount);

        // Le payload dit exactement ce que la base porte, avant/après lus sur le vrai
        // solde — d'où le compte, qui part de zéro.
        $coins = $reward['coins'];
        self::assertIsArray($coins);
        self::assertSame($amount, $coins['gained']);
        self::assertSame(0, $coins['before']);
        self::assertSame($amount, $coins['after']);

        $loot = $reward['loot'];
        self::assertIsArray($loot);
        self::assertLessThanOrEqual(1, \count($loot), 'Un seul tirage : au plus un objet.');

        if ([] !== $loot) {
            $item = $loot[0];
            self::assertIsArray($item);
            self::assertSame(['key', 'kind', 'name', 'rarity', 'slot', 'modifiers', 'priceCoins'], array_keys($item));
            self::assertContains($item['key'], ['WORN_RUNNING_SHOES', 'IRON_GAUNTLETS', 'TRAVELERS_CLOAK']);

            $inventory = $this->connection()->fetchAssociative(
                'SELECT item_key, quantity, loot_roll_id FROM rewards_inventory_item WHERE user_id = :id',
                ['id' => $bob->id->toRfc4122()],
            );
            self::assertIsArray($inventory);
            self::assertSame($item['key'], $inventory['item_key']);
            self::assertIsNumeric($inventory['quantity']);
            self::assertSame(1, (int) $inventory['quantity']);
        }
    }

    /**
     * **Le test qui porte le #167 côté loot.** La marche ne crédite pas d'XP par
     * conception ; elle ne fait tomber ni objet ni pièce non plus, mais le `RewardSummary`
     * porte quand même la forme complète — un `loot` vide et des `coins` à gain nul, jamais
     * des clés absentes.
     */
    public function testAWalkingSessionRollsNothing(): void
    {
        $bob = $this->openAccount();

        $body = self::decode($this->import($bob, [self::candidate(activityType: 'walking', durationSeconds: 2700)]));

        self::assertIsArray($body['imported']);
        $reward = $body['imported'][0];
        self::assertIsArray($reward);

        self::assertSame([], $reward['loot']);
        self::assertSame(['gained' => 0, 'before' => 0, 'after' => 0], $reward['coins']);

        self::assertSame(0, $this->countRows('rewards_loot_roll'));
        self::assertSame(0, $this->countRows('rewards_coin_transaction'));
        self::assertSame(0, $this->countRows('rewards_inventory_item'));
    }

    /**
     * Une séance dont le niveau **d'après son propre crédit** franchit le seuil d'une table
     * plus exigeante tire sur celle-ci, pas sur celle que son niveau de départ aurait
     * choisie — même geste que les titres, évalués après l'écriture du ledger.
     */
    public function testTheTableEligibleDependsOnTheLevelReachedAfterThisWorkoutIsCredited(): void
    {
        $bob = $this->openAccount();

        // Quatre crédits directs, sur quatre jours distincts pour ne heurter aucun
        // plafond quotidien (400 XP/jour en course) : 260 XP chacun, 1040 au total — assez
        // pour franchir le niveau 5 (760 XP), loin du niveau 20 (12 160 XP) de
        // `VETERAN_SESSION_DROP`.
        for ($daysAgo = 20; $daysAgo > 16; --$daysAgo) {
            ($this->grantXp)(new GrantXp(
                $bob->id,
                Uuid::v7(),
                Discipline::Running,
                3600,
                new DateTimeImmutable(\sprintf('-%d days', $daysAgo)),
                distanceMeters: 20_000,
            ));
        }

        // La séance testée, 60 minutes — au-dessus du seuil de `TRAINED_SESSION_DROP`
        // (45 minutes, niveau 5).
        $body = self::decode($this->import($bob, [self::candidate(startedAt: '2026-08-11T07:00:00+00:00', endedAt: '2026-08-11T08:00:00+00:00')]));

        self::assertIsArray($body['imported']);
        $level = $body['imported'][0];
        self::assertIsArray($level);
        self::assertIsArray($level['level']);
        self::assertGreaterThanOrEqual(5, $level['level']['after']);
        self::assertLessThan(20, $level['level']['after']);

        $tableKey = $this->connection()->fetchOne(
            'SELECT table_key FROM rewards_loot_roll WHERE user_id = :id',
            ['id' => $bob->id->toRfc4122()],
        );
        self::assertSame('TRAINED_SESSION_DROP', $tableKey);
    }

    /**
     * **Le test qui porte le ticket, sur un lot.** Le même lot, présenté dans deux ordres
     * différents à deux comptes, tire sur la même table pour chacune de ses deux séances :
     * la table éligible ne dépend que du niveau et de la durée du workout, jamais de
     * l'ordre dans lequel le client les a empilés dans le corps de la requête — c'est le
     * tri chronologique de `ImportWorkoutsHandler` qui l'y ramène avant même la première
     * ligne de crédit.
     *
     * Les montants tirés, eux, ne peuvent pas être comparés à l'identique : chaque workout
     * tire sa propre graine (`random_bytes(32)`), et deux comptes différents ne partagent
     * jamais leur hasard — voir le docblock de la classe.
     */
    public function testTheSameBatchInAnyOrderRollsTheSameTables(): void
    {
        $bob = $this->openAccount();
        $alice = $this->openAccount('alice@grrind.app', 'Alice');

        $matin = self::candidate(externalId: 'HK-MATIN', startedAt: '2026-08-05T06:00:00+00:00', endedAt: '2026-08-05T06:45:00+00:00');
        $soir = self::candidate(externalId: 'HK-SOIR', startedAt: '2026-08-05T18:00:00+00:00', endedAt: '2026-08-05T18:45:00+00:00');

        $this->import($bob, [$matin, $soir]);
        $this->import($alice, [$soir, $matin]);

        self::assertSame(['STARTER_SESSION_DROP', 'STARTER_SESSION_DROP'], $this->tableKeysOf($bob));
        self::assertSame(['STARTER_SESSION_DROP', 'STARTER_SESSION_DROP'], $this->tableKeysOf($alice));
    }

    /**
     * @param list<array<string, mixed>> $workouts
     */
    private function import(Account $account, array $workouts, string $key = 'import-du-jour'): Response
    {
        return $this->post(
            '/api/workouts/import',
            ['workouts' => $workouts],
            $account->headers + ['Idempotency-Key' => $key],
        );
    }

    /**
     * @return list<string>
     */
    private function tableKeysOf(Account $account): array
    {
        $keys = $this->connection()->fetchFirstColumn(
            'SELECT table_key FROM rewards_loot_roll WHERE user_id = :id ORDER BY rolled_at',
            ['id' => $account->id->toRfc4122()],
        );

        foreach ($keys as $key) {
            self::assertIsString($key);
        }

        /** @var list<string> $keys */
        return $keys;
    }

    private function countRows(string $table): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM '.$table);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    /**
     * @return array<string, mixed>
     */
    private static function candidate(
        string $externalId = 'HK-001',
        string $activityType = 'running',
        string $startedAt = '2026-08-11T07:00:00+00:00',
        ?string $endedAt = null,
        int $durationSeconds = 2700,
    ): array {
        $endedAt ??= new DateTimeImmutable($startedAt)
            ->modify(\sprintf('+%d seconds', $durationSeconds))
            ->format(DateTimeInterface::ATOM);

        return [
            'externalId' => $externalId,
            'source' => 'APPLE_HEALTH',
            'activityType' => $activityType,
            'startedAt' => $startedAt,
            'endedAt' => $endedAt,
        ];
    }
}
