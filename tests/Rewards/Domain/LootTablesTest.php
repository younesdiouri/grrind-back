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
        $tables = new LootTables(1, [self::workout('ANY', [], 0, 1)], [], [['key' => 'BOOTS']], [], []);

        self::assertTrue($tables->workoutTables()[0]->isEligibleFor(Discipline::Swimming, 0, 1));
    }

    public function testForAdversaryRendLaTableDeLaCleConnue(): void
    {
        $tables = new LootTables(
            1,
            [],
            [self::adversary('SAND_JACKAL', ['SHIELD'])],
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
        $tables = new LootTables(1, [], [], [], [['key' => 'SAND_JACKAL']], []);

        self::assertNull($tables->forAdversary('SAND_JACKAL'));
    }

    public function testRefuseUneVersionSousUn(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LootTables(0, [self::workout('TIER_ONE', [], 0, 1)], [], [], [], []);
    }

    public function testRefuseDeuxTablesDeSeancePourLaMemeCle(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LootTables(
            1,
            [self::workout('TIER_ONE', [], 0, 1), self::workout('TIER_ONE', [], 0, 1)],
            [],
            [['key' => 'BOOTS']],
            [],
            [],
        );
    }

    public function testRefuseUneCleDAdversaireInconnue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LootTables(1, [], [self::adversary('GHOST_JACKAL', [])], [], [['key' => 'SAND_JACKAL']], []);
    }

    public function testRefuseDeuxTablesPourLeMemeAdversaire(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LootTables(
            1,
            [],
            [self::adversary('SAND_JACKAL', []), self::adversary('SAND_JACKAL', [])],
            [],
            [['key' => 'SAND_JACKAL']],
            [],
        );
    }

    public function testRefuseUneEntreePointantVersUnObjetInexistant(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LootTables(1, [], [self::adversary('SAND_JACKAL', ['GHOST_ITEM'])], [], [['key' => 'SAND_JACKAL']], []);
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
            [['key' => 'SAND_JACKAL']],
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
     * @param list<string> $itemKeys
     *
     * @return array{key: string, coins: array{minimum: int, maximum: int}, entries: list<array{item?: string, weight: int}>}
     */
    private static function adversary(string $key, array $itemKeys): array
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
