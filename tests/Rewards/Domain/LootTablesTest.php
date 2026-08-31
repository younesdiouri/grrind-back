<?php

declare(strict_types=1);

namespace App\Tests\Rewards\Domain;

use App\Rewards\Domain\LootTables;
use App\Shared\Domain\Activity\Discipline;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Le moteur de jeu se teste sans aucune infra — voir `ItemCatalogTest`. Les clés connues
 * (`$items`, `$enemies`, `$bosses`) sont ici de simples listes fabriquées pour le test :
 * en production, elles viennent des paramètres réels du conteneur — voir
 * `RewardsCoverageTest` et le docblock de `LootTables` pour pourquoi le croisement ne peut
 * pas se faire plus tôt, à la compilation de `LootSection`.
 */
final class LootTablesTest extends TestCase
{
    public function testWorkoutTablesExposentLeurEligibilite(): void
    {
        $tables = new LootTables(
            1,
            [self::workout('TIER_ONE', ['RUNNING'], 20, 5)],
            [],
            [],
            [['key' => 'BOOTS']],
            [],
            [],
        );

        $tier = $tables->workoutTables()[0];

        self::assertSame('TIER_ONE', $tier->key);
        self::assertSame([Discipline::Running], $tier->disciplines);
        self::assertTrue($tier->isEligibleFor(Discipline::Running, 30, 6));
        self::assertFalse($tier->isEligibleFor(Discipline::Cycling, 30, 6), 'mauvaise discipline');
        self::assertFalse($tier->isEligibleFor(Discipline::Running, 10, 6), 'durée sous le minimum');
        self::assertFalse($tier->isEligibleFor(Discipline::Running, 30, 4), 'niveau sous le minimum');
    }

    public function testUneListeDeDisciplinesVideAccepteToutSport(): void
    {
        $tables = new LootTables(1, [self::workout('ANY', [], 0, 1)], [], [], [['key' => 'BOOTS']], [], []);

        self::assertTrue($tables->workoutTables()[0]->isEligibleFor(Discipline::Swimming, 0, 1));
    }

    public function testForAdversaryRendLaTableDeLaCleConnue(): void
    {
        $tables = new LootTables(
            1,
            [],
            [self::table('SAND_JACKAL', ['SHIELD'])],
            [],
            [['key' => 'SHIELD']],
            [['key' => 'SAND_JACKAL']],
            [],
        );

        $table = $tables->forAdversary('SAND_JACKAL');

        self::assertNotNull($table);
        self::assertSame(2, $table->coins->minimum);
        self::assertSame(8, $table->coins->maximum);
    }

    public function testForAdversaryRendNullPourUneCleSansTable(): void
    {
        $tables = new LootTables(1, [], [], [], [], [['key' => 'SAND_JACKAL']], []);

        self::assertNull($tables->forAdversary('SAND_JACKAL'));
    }

    public function testRefuseUneVersionSousUn(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LootTables(0, [self::workout('TIER_ONE', [], 0, 1)], [], [], [], [], []);
    }

    public function testRefuseDeuxTablesDeSeancePourLaMemeCle(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LootTables(
            1,
            [self::workout('TIER_ONE', [], 0, 1), self::workout('TIER_ONE', [], 0, 1)],
            [],
            [],
            [['key' => 'BOOTS']],
            [],
            [],
        );
    }

    public function testRefuseUneCleDAdversaireInconnue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LootTables(1, [], [self::table('GHOST_JACKAL', [])], [], [], [['key' => 'SAND_JACKAL']], []);
    }

    public function testRefuseDeuxTablesPourLeMemeAdversaire(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LootTables(
            1,
            [],
            [self::table('SAND_JACKAL', []), self::table('SAND_JACKAL', [])],
            [],
            [],
            [['key' => 'SAND_JACKAL']],
            [],
        );
    }

    public function testRefuseUneEntreePointantVersUnObjetInexistant(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LootTables(1, [], [self::table('SAND_JACKAL', ['GHOST_ITEM'])], [], [], [['key' => 'SAND_JACKAL']], []);
    }

    public function testRefuseUneSommeDePoidsNulle(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LootTables(
            1,
            [],
            [[
                'key' => 'SAND_JACKAL',
                'coins' => ['minimum' => 2, 'maximum' => 8],
                'entries' => [['weight' => 0]],
            ]],
            [],
            [],
            [['key' => 'SAND_JACKAL']],
            [],
        );
    }

    public function testRefuseUneTableSansEntreeRien(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LootTables(
            1,
            [],
            [[
                'key' => 'SAND_JACKAL',
                'coins' => ['minimum' => 2, 'maximum' => 8],
                'entries' => [['item' => 'BOOTS', 'weight' => 10]],
            ]],
            [],
            [['key' => 'BOOTS']],
            [['key' => 'SAND_JACKAL']],
            [],
        );
    }

    public function testRefuseUneBandeDePiecesInversee(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LootTables(
            1,
            [],
            [[
                'key' => 'SAND_JACKAL',
                'coins' => ['minimum' => 20, 'maximum' => 5],
                'entries' => [['weight' => 100]],
            ]],
            [],
            [],
            [['key' => 'SAND_JACKAL']],
            [],
        );
    }

    /**
     * Le jumeau exact du test adversaire — même geste, une clé de coffre plutôt qu'une clé
     * d'ennemi.
     */
    public function testForChestRendLaTableDeLaCleConnue(): void
    {
        $tables = new LootTables(
            1,
            [],
            [],
            [self::table('WOODEN_CHEST', ['BOOTS'])],
            [['key' => 'BOOTS'], ['key' => 'WOODEN_CHEST', 'kind' => 'CHEST']],
            [],
            [],
        );

        $table = $tables->forChest('WOODEN_CHEST');

        self::assertNotNull($table);
        self::assertSame(2, $table->coins->minimum);
        self::assertSame(8, $table->coins->maximum);
    }

    public function testForChestRendNullPourUneCleSansTable(): void
    {
        $tables = new LootTables(1, [], [], [], [['key' => 'WOODEN_CHEST', 'kind' => 'CHEST']], [], []);

        self::assertNull($tables->forChest('WOODEN_CHEST'));
    }

    /** Une table de coffre pour une clé qui n'est pas un coffre du catalogue — même refus qu'une clé d'adversaire inconnue. */
    public function testRefuseUneTableDeCoffrePourUneCleQuiNEstPasUnCoffre(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LootTables(
            1,
            [],
            [],
            [self::table('IRON_GAUNTLETS', [])],
            [['key' => 'IRON_GAUNTLETS']],
            [],
            [],
        );
    }

    public function testRefuseDeuxTablesPourLeMemeCoffre(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LootTables(
            1,
            [],
            [],
            [self::table('WOODEN_CHEST', []), self::table('WOODEN_CHEST', [])],
            [['key' => 'WOODEN_CHEST', 'kind' => 'CHEST']],
            [],
            [],
        );
    }

    /** Le cœur du #230 : une table de coffre ne peut pas contenir de coffre. */
    public function testRefuseUneTableDeCoffreQuiContientUnCoffre(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('WOODEN_CHEST');

        new LootTables(
            1,
            [],
            [],
            [self::table('WOODEN_CHEST', ['IRON_BOUND_CHEST'])],
            [
                ['key' => 'WOODEN_CHEST', 'kind' => 'CHEST'],
                ['key' => 'IRON_BOUND_CHEST', 'kind' => 'CHEST'],
            ],
            [],
            [],
        );
    }

    /**
     * @param list<string> $disciplines
     *
     * @return array{key: string, eligibility: array{disciplines: list<string>, minimum_duration_minutes: int, minimum_level: int}, coins: array{minimum: int, maximum: int}, entries: list<array{item?: string, weight: int}>}
     */
    private static function workout(string $key, array $disciplines, int $minimumDurationMinutes, int $minimumLevel): array
    {
        return [
            'key' => $key,
            'eligibility' => [
                'disciplines' => $disciplines,
                'minimum_duration_minutes' => $minimumDurationMinutes,
                'minimum_level' => $minimumLevel,
            ],
            'coins' => ['minimum' => 5, 'maximum' => 15],
            'entries' => [['weight' => 70], ['item' => 'BOOTS', 'weight' => 30]],
        ];
    }

    /**
     * Une table à condition unique — l'adversaire ou le coffre choisi *est* la condition,
     * même forme pour les deux origines.
     *
     * @param list<string> $itemKeys
     *
     * @return array{key: string, coins: array{minimum: int, maximum: int}, entries: list<array{item?: string, weight: int}>}
     */
    private static function table(string $key, array $itemKeys): array
    {
        $entries = [['weight' => 80]];

        foreach ($itemKeys as $itemKey) {
            $entries[] = ['item' => $itemKey, 'weight' => 10];
        }

        return [
            'key' => $key,
            'coins' => ['minimum' => 2, 'maximum' => 8],
            'entries' => $entries,
        ];
    }
}
