<?php

declare(strict_types=1);

namespace App\Tests\Shared\Config;

use App\Combat\Domain\EnemyCatalog;
use App\Rewards\Domain\ItemCatalog;
use App\Rewards\Domain\LootTables;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Les tables livrées, celles de `config/game/v1/items.yaml` et `config/game/v1/loot.yaml`,
 * contre ce que le domaine exige réellement — même geste que `CombatCoverageTest` et
 * `AttributeSplitCoverageTest`.
 *
 * **C'est ici, et pas dans `ItemsSection` ni `LootSection`, que le vrai croisement entre
 * les trois fichiers se prouve** — voir le docblock de `LootTables` pour pourquoi
 * `GameBalanceLoader` ne peut pas le faire à la compilation de chaque section
 * indépendamment. Construire `LootTables` depuis les paramètres du conteneur, exactement
 * comme `services.yaml` le fait, est ce qui donne une vraie garantie : une clé d'objet ou
 * d'adversaire qui se serait glissée dans `loot.yaml` sans exister ailleurs fait échouer ce
 * test, pas une requête en production.
 */
final class RewardsCoverageTest extends KernelTestCase
{
    public function testLeCatalogueDObjetsLivreConstruitSansErreur(): void
    {
        $catalog = self::shippedCatalog();

        self::assertNotCount(0, $catalog->all());
    }

    /**
     * Le point que ni `ItemsSection` ni `LootSection` ne peuvent prouver seules : chaque
     * entrée de `loot.yaml` qui référence un objet référence un objet qui existe
     * réellement dans `items.yaml` — sinon cette construction aurait déjà jeté.
     */
    public function testLesTablesDeTirageLivreesConstruisentSansErreur(): void
    {
        $tables = self::shippedTables();

        self::assertNotCount(0, $tables->workoutTables());
    }

    /**
     * Chaque ennemi et chaque boss de `combat.yaml` a sa table de tirage : un adversaire
     * sans table ne ferait jamais tomber de récompense, un bug silencieux qu'aucune
     * exception ne signale — `forAdversary()` rend `null` en toute légitimité pour une clé
     * qui n'a simplement pas encore de table.
     */
    public function testChaqueAdversaireDuCatalogueALivreATableDeTirage(): void
    {
        $tables = self::shippedTables();
        $catalog = self::shippedEnemyCatalog();

        foreach ([...$catalog->all(), ...$catalog->bosses()] as $adversary) {
            self::assertNotNull($tables->forAdversary($adversary->key), \sprintf('"%s" n\'a pas de table de tirage dans loot.yaml.', $adversary->key));
        }
    }

    public function testLaVersionDesTablesEstExposeeIndependammentDuRulesetVersion(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertSame(1, $container->getParameter('game.loot.version'));
        self::assertIsString($container->getParameter('game.ruleset_version'));
    }

    private static function shippedCatalog(): ItemCatalog
    {
        self::bootKernel();
        $container = self::getContainer();

        $items = $container->getParameter('game.items.items');
        self::assertIsArray($items);

        /** @var list<array{key: string, rarity: string, slot: string, price_coins: int, modifiers: list<array{type: string, value: int, discipline?: string}>}> $items */
        return new ItemCatalog($items);
    }

    private static function shippedTables(): LootTables
    {
        self::bootKernel();
        $container = self::getContainer();

        $version = self::intParameter($container, 'game.loot.version');

        $workout = $container->getParameter('game.loot.workout');
        $adversary = $container->getParameter('game.loot.adversary');
        $items = $container->getParameter('game.items.items');
        $enemies = $container->getParameter('game.combat.enemies');
        $bosses = $container->getParameter('game.combat.bosses');

        self::assertIsArray($workout);
        self::assertIsArray($adversary);
        self::assertIsArray($items);
        self::assertIsArray($enemies);
        self::assertIsArray($bosses);

        /**
         * @var list<array{key: string, eligibility: array{disciplines: list<string>, minimum_duration_minutes: int, minimum_level: int}, coins: array{minimum: int, maximum: int}, entries: list<array{item?: string, weight: int}>}> $workout
         * @var list<array{key: string, coins: array{minimum: int, maximum: int}, entries: list<array{item?: string, weight: int}>}>                                                                                                   $adversary
         * @var list<array{key: string, rarity: string, slot: string, price_coins: int, modifiers: list<array{type: string, value: int, discipline?: string}>}>                                                                        $items
         * @var list<array{key: string}>                                                                                                                                                                                               $enemies
         * @var list<array{key: string}>                                                                                                                                                                                               $bosses
         */
        return new LootTables($version, $workout, $adversary, $items, $enemies, $bosses);
    }

    private static function shippedEnemyCatalog(): EnemyCatalog
    {
        self::bootKernel();
        $container = self::getContainer();

        $enemies = $container->getParameter('game.combat.enemies');
        $bosses = $container->getParameter('game.combat.bosses');
        self::assertIsArray($enemies);
        self::assertIsArray($bosses);

        /** @var list<array{key: string, level: int, hp: int, damage: int, mitigation_permille: int, extra_turn_permille: int, dodge_permille: int}> $enemies */
        /** @var list<array{key: string, minimum_level: int, hp: int, damage: int, mitigation_permille: int, extra_turn_permille: int, dodge_permille: int}> $bosses */
        return new EnemyCatalog($enemies, $bosses);
    }

    private static function intParameter(ContainerInterface $container, string $name): int
    {
        $value = $container->getParameter($name);
        self::assertIsInt($value);

        return $value;
    }
}
