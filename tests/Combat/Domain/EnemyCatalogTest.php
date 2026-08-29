<?php

declare(strict_types=1);

namespace App\Tests\Combat\Domain;

use App\Combat\Domain\Enemy;
use App\Combat\Domain\EnemyCatalog;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Le catalogue est du config-as-code : ce qui se vérifie ici est autant ce qu'il calcule que
 * ce qu'il **refuse**. Un catalogue incohérent doit casser la compilation du conteneur, pas
 * se découvrir le jour où un joueur ne trouve aucun adversaire.
 */
final class EnemyCatalogTest extends TestCase
{
    public function testFindsAnEnemyByItsKey(): void
    {
        $catalog = self::catalogOf(
            ['key' => 'SAND_JACKAL', 'level' => 1, 'hp' => 120, 'damage' => 12, 'mitigation_permille' => 50, 'extra_turn_permille' => 40, 'dodge_permille' => 30],
        );

        $enemy = $catalog->find('SAND_JACKAL');

        self::assertInstanceOf(Enemy::class, $enemy);
        self::assertSame(120, $enemy->hp);
        self::assertNull($catalog->find('DUNE_RAIDER'));
    }

    public function testFindsTheExactLevelWhenItExists(): void
    {
        $catalog = self::catalogOf(
            ['key' => 'SAND_JACKAL', 'level' => 1, 'hp' => 120, 'damage' => 12, 'mitigation_permille' => 50, 'extra_turn_permille' => 40, 'dodge_permille' => 30],
            ['key' => 'DUNE_RAIDER', 'level' => 5, 'hp' => 220, 'damage' => 18, 'mitigation_permille' => 80, 'extra_turn_permille' => 60, 'dodge_permille' => 30],
        );

        self::assertSame('DUNE_RAIDER', $catalog->forLevel(5)->key);
    }

    /**
     * Le catalogue ne couvre pas forcément chaque niveau : au-delà du dernier ennemi livré,
     * c'est lui qui reste opposé, jamais `null` — voir le docblock de la classe.
     */
    public function testFallsBackToTheHighestEnemyAtOrBelowThePlayerLevel(): void
    {
        $catalog = self::catalogOf(
            ['key' => 'SAND_JACKAL', 'level' => 1, 'hp' => 120, 'damage' => 12, 'mitigation_permille' => 50, 'extra_turn_permille' => 40, 'dodge_permille' => 30],
            ['key' => 'DUNE_RAIDER', 'level' => 5, 'hp' => 220, 'damage' => 18, 'mitigation_permille' => 80, 'extra_turn_permille' => 60, 'dodge_permille' => 30],
        );

        // Niveau 3 : rien d'écrit pour ce palier, le chacal du niveau 1 reste opposé.
        self::assertSame('SAND_JACKAL', $catalog->forLevel(3)->key);

        // Niveau 99 : au-delà du dernier ennemi livré, c'est encore lui qui répond.
        self::assertSame('DUNE_RAIDER', $catalog->forLevel(99)->key);
    }

    public function testAnEmptyCatalogueIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EnemyCatalog([]);
    }

    /**
     * Deux ennemis pour le même niveau feraient taire silencieusement l'un des deux :
     * `forLevel()` ne pourrait rendre que le dernier écrit.
     */
    public function testTwoEnemiesForTheSameLevelIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::catalogOf(
            ['key' => 'SAND_JACKAL', 'level' => 1, 'hp' => 120, 'damage' => 12, 'mitigation_permille' => 50, 'extra_turn_permille' => 40, 'dodge_permille' => 30],
            ['key' => 'DUNE_RAIDER', 'level' => 1, 'hp' => 220, 'damage' => 18, 'mitigation_permille' => 80, 'extra_turn_permille' => 60, 'dodge_permille' => 30],
        );
    }

    /**
     * Le niveau 1 est celui qu'un compte neuf rencontre : son absence laisserait le premier
     * combat sans adversaire.
     */
    public function testAMissingLevelOneIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::catalogOf(
            ['key' => 'DUNE_RAIDER', 'level' => 5, 'hp' => 220, 'damage' => 18, 'mitigation_permille' => 80, 'extra_turn_permille' => 60, 'dodge_permille' => 30],
        );
    }

    /**
     * Même refus que {@see CombatRulesTest} sur le combattant dérivé, et pour la même
     * raison : à 1000 ‰ de mitigation, un ennemi devient invulnérable. `CombatSection` ne
     * borne ce champ que par le bas — voir le docblock de la classe — donc c'est ici que
     * le refus doit vivre.
     */
    public function testAnEnemyReachingInvulnerabilityIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::catalogOf(
            ['key' => 'SAND_JACKAL', 'level' => 1, 'hp' => 120, 'damage' => 12, 'mitigation_permille' => 1000, 'extra_turn_permille' => 40, 'dodge_permille' => 30],
        );
    }

    /**
     * Même refus, par l'autre chemin : à 1000 ‰ de tour supplémentaire, un ennemi ne rend
     * jamais la main.
     */
    public function testAnEnemyNeverYieldingTheirTurnIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::catalogOf(
            ['key' => 'SAND_JACKAL', 'level' => 1, 'hp' => 120, 'damage' => 12, 'mitigation_permille' => 50, 'extra_turn_permille' => 1000, 'dodge_permille' => 30],
        );
    }

    /**
     * Même refus, par un troisième chemin (#218) : à 1000 ‰ d'esquive, un ennemi
     * n'encaisserait plus jamais rien.
     */
    public function testAnEnemyDodgingEveryAttackIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::catalogOf(
            ['key' => 'SAND_JACKAL', 'level' => 1, 'hp' => 120, 'damage' => 12, 'mitigation_permille' => 50, 'extra_turn_permille' => 40, 'dodge_permille' => 1000],
        );
    }

    /**
     * Un boss se retrouve par sa clé, jamais par `forLevel()` : il n'entre pas dans
     * `byLevel`, voir le docblock de la classe.
     */
    public function testFindsABossByItsKeyAndNeverThroughForLevel(): void
    {
        $catalog = self::catalogWithBosses(
            [
                ['key' => 'SAND_JACKAL', 'level' => 1, 'hp' => 120, 'damage' => 12, 'mitigation_permille' => 50, 'extra_turn_permille' => 40, 'dodge_permille' => 30],
            ],
            [
                ['key' => 'DUNE_SOVEREIGN', 'minimum_level' => 10, 'hp' => 700, 'damage' => 40, 'mitigation_permille' => 200, 'extra_turn_permille' => 120, 'dodge_permille' => 90],
            ],
        );

        $boss = $catalog->findBoss('DUNE_SOVEREIGN');

        self::assertInstanceOf(Enemy::class, $boss);
        self::assertSame(10, $boss->level, '`minimum_level` alimente le même champ `level` qu\'un ennemi ordinaire.');
        self::assertNull($catalog->find('DUNE_SOVEREIGN'), 'Un boss ne se trouve pas par `find()`.');
        self::assertNull($catalog->findBoss('SAND_JACKAL'), 'Un ennemi ordinaire ne se trouve pas par `findBoss()`.');

        // `forLevel()` ne rend jamais un boss, quel que soit le niveau demandé.
        self::assertSame('SAND_JACKAL', $catalog->forLevel(99)->key);
    }

    public function testBossesAreListedSeparatelyFromEnemies(): void
    {
        $catalog = self::catalogWithBosses(
            [
                ['key' => 'SAND_JACKAL', 'level' => 1, 'hp' => 120, 'damage' => 12, 'mitigation_permille' => 50, 'extra_turn_permille' => 40, 'dodge_permille' => 30],
            ],
            [
                ['key' => 'DUNE_SOVEREIGN', 'minimum_level' => 10, 'hp' => 700, 'damage' => 40, 'mitigation_permille' => 200, 'extra_turn_permille' => 120, 'dodge_permille' => 90],
            ],
        );

        self::assertCount(1, $catalog->all());
        self::assertCount(1, $catalog->bosses());
        self::assertSame('DUNE_SOVEREIGN', $catalog->bosses()[0]->key);
    }

    /**
     * Contrairement aux ennemis ordinaires, deux boss peuvent partager un `minimum_level` :
     * seule la clé les distingue, voir le docblock de la classe.
     */
    public function testTwoBossesCanShareTheSameMinimumLevel(): void
    {
        $catalog = self::catalogWithBosses(
            [
                ['key' => 'SAND_JACKAL', 'level' => 1, 'hp' => 120, 'damage' => 12, 'mitigation_permille' => 50, 'extra_turn_permille' => 40, 'dodge_permille' => 30],
            ],
            [
                ['key' => 'DUNE_SOVEREIGN', 'minimum_level' => 10, 'hp' => 700, 'damage' => 40, 'mitigation_permille' => 200, 'extra_turn_permille' => 120, 'dodge_permille' => 90],
                ['key' => 'STORM_MATRIARCH', 'minimum_level' => 10, 'hp' => 1150, 'damage' => 62, 'mitigation_permille' => 260, 'extra_turn_permille' => 170, 'dodge_permille' => 130],
            ],
        );

        self::assertCount(2, $catalog->bosses());
    }

    public function testTwoBossesForTheSameKeyIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::catalogWithBosses(
            [
                ['key' => 'SAND_JACKAL', 'level' => 1, 'hp' => 120, 'damage' => 12, 'mitigation_permille' => 50, 'extra_turn_permille' => 40, 'dodge_permille' => 30],
            ],
            [
                ['key' => 'DUNE_SOVEREIGN', 'minimum_level' => 10, 'hp' => 700, 'damage' => 40, 'mitigation_permille' => 200, 'extra_turn_permille' => 120, 'dodge_permille' => 90],
                ['key' => 'DUNE_SOVEREIGN', 'minimum_level' => 20, 'hp' => 1150, 'damage' => 62, 'mitigation_permille' => 260, 'extra_turn_permille' => 170, 'dodge_permille' => 130],
            ],
        );
    }

    /**
     * `find()` et `findBoss()` doivent rester sans ambiguïté — voir le docblock de la
     * classe : une clé des deux côtés leur ferait rendre des réponses différentes selon
     * celle qu'on appelle.
     */
    public function testAKeyUsedByBothAnEnemyAndABossIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::catalogWithBosses(
            [
                ['key' => 'SAND_JACKAL', 'level' => 1, 'hp' => 120, 'damage' => 12, 'mitigation_permille' => 50, 'extra_turn_permille' => 40, 'dodge_permille' => 30],
            ],
            [
                ['key' => 'SAND_JACKAL', 'minimum_level' => 10, 'hp' => 700, 'damage' => 40, 'mitigation_permille' => 200, 'extra_turn_permille' => 120, 'dodge_permille' => 90],
            ],
        );
    }

    /**
     * Même refus qu'un ennemi ordinaire, et pour la même raison : à 1000 ‰ de mitigation, un
     * boss deviendrait invulnérable. `CombatSection` ne borne ce champ que par le bas, donc
     * c'est ici que le refus doit vivre.
     */
    public function testABossReachingInvulnerabilityIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::catalogWithBosses(
            [
                ['key' => 'SAND_JACKAL', 'level' => 1, 'hp' => 120, 'damage' => 12, 'mitigation_permille' => 50, 'extra_turn_permille' => 40, 'dodge_permille' => 30],
            ],
            [
                ['key' => 'DUNE_SOVEREIGN', 'minimum_level' => 10, 'hp' => 700, 'damage' => 40, 'mitigation_permille' => 1000, 'extra_turn_permille' => 120, 'dodge_permille' => 90],
            ],
        );
    }

    /**
     * Même refus, par un second chemin : un boss qui ne rendrait jamais la main.
     */
    public function testABossNeverYieldingTheirTurnIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::catalogWithBosses(
            [
                ['key' => 'SAND_JACKAL', 'level' => 1, 'hp' => 120, 'damage' => 12, 'mitigation_permille' => 50, 'extra_turn_permille' => 40, 'dodge_permille' => 30],
            ],
            [
                ['key' => 'DUNE_SOVEREIGN', 'minimum_level' => 10, 'hp' => 700, 'damage' => 40, 'mitigation_permille' => 200, 'extra_turn_permille' => 1000, 'dodge_permille' => 90],
            ],
        );
    }

    /**
     * Même refus, par un troisième chemin (#218) : un boss qui esquiverait toujours.
     */
    public function testABossDodgingEveryAttackIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::catalogWithBosses(
            [
                ['key' => 'SAND_JACKAL', 'level' => 1, 'hp' => 120, 'damage' => 12, 'mitigation_permille' => 50, 'extra_turn_permille' => 40, 'dodge_permille' => 30],
            ],
            [
                ['key' => 'DUNE_SOVEREIGN', 'minimum_level' => 10, 'hp' => 700, 'damage' => 40, 'mitigation_permille' => 200, 'extra_turn_permille' => 120, 'dodge_permille' => 1000],
            ],
        );
    }

    /**
     * @param array{key: string, level: int, hp: int, damage: int, mitigation_permille: int, extra_turn_permille: int, dodge_permille: int} ...$enemies
     */
    private static function catalogOf(array ...$enemies): EnemyCatalog
    {
        return new EnemyCatalog(array_values($enemies));
    }

    /**
     * @param list<array{key: string, level: int, hp: int, damage: int, mitigation_permille: int, extra_turn_permille: int, dodge_permille: int}>         $enemies
     * @param list<array{key: string, minimum_level: int, hp: int, damage: int, mitigation_permille: int, extra_turn_permille: int, dodge_permille: int}> $bosses
     */
    private static function catalogWithBosses(array $enemies, array $bosses): EnemyCatalog
    {
        return new EnemyCatalog($enemies, $bosses);
    }
}
