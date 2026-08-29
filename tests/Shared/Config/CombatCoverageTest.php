<?php

declare(strict_types=1);

namespace App\Tests\Shared\Config;

use App\Combat\Domain\CombatRules;
use App\Combat\Domain\EnemyCatalog;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * La table livrée, celle de `config/game/v1/combat.yaml`, contre ce que le domaine exige
 * réellement — même geste qu'`AttributeSplitCoverageTest` pour la répartition d'XP. Que la
 * compilation ait abouti prouve déjà que `CombatSection` a validé les socles et le
 * catalogue sans broncher ; ce test vérifie ce qu'ils valent, construits depuis les
 * paramètres du conteneur comme `services.yaml` le fait réellement.
 */
final class CombatCoverageTest extends KernelTestCase
{
    public function testExposesTheFighterCoefficientsAsTheirOwnParameters(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertSame(100, $container->getParameter('game.combat.fighter.base_hp'));
        self::assertSame(200, $container->getParameter('game.combat.fighter.max_turns'));
    }

    public function testTheShippedFighterCoefficientsAreCoherent(): void
    {
        // Que la construction n'ait pas jeté prouve déjà la cohérence ; on vérifie ici
        // qu'elle porte bien les valeurs livrées.
        $rules = self::shippedRules();

        self::assertLessThan(1000, $rules->mitigationCapPermille);
        self::assertLessThan(1000, $rules->extraTurnCapPermille);
        self::assertGreaterThanOrEqual(1, $rules->minimumDamage);
    }

    public function testTheShippedCatalogueCoversTheLevelOneEncounter(): void
    {
        $catalog = self::shippedCatalog();

        $starter = $catalog->forLevel(1);

        self::assertSame(1, $starter->level);
        self::assertGreaterThan(0, $starter->hp);
    }

    public function testTheShippedCatalogueHasNoDuplicateLevel(): void
    {
        $enemies = self::getContainer()->getParameter('game.combat.enemies');
        self::assertIsArray($enemies);

        $levels = [];
        foreach ($enemies as $enemy) {
            self::assertIsArray($enemy);
            $levels[] = $enemy['level'];
        }

        self::assertSame($levels, array_unique($levels), 'Le catalogue livré porte deux ennemis pour le même niveau.');
    }

    private static function shippedRules(): CombatRules
    {
        self::bootKernel();
        $container = self::getContainer();

        return new CombatRules(
            self::intParameter($container, 'game.combat.fighter.base_hp'),
            self::intParameter($container, 'game.combat.fighter.hp_per_1000_vitality'),
            self::intParameter($container, 'game.combat.fighter.base_damage'),
            self::intParameter($container, 'game.combat.fighter.damage_per_1000_strength'),
            self::intParameter($container, 'game.combat.fighter.mitigation_permille_per_1000_endurance'),
            self::intParameter($container, 'game.combat.fighter.mitigation_cap_permille'),
            self::intParameter($container, 'game.combat.fighter.extra_turn_permille_per_1000_dexterity'),
            self::intParameter($container, 'game.combat.fighter.extra_turn_cap_permille'),
            self::intParameter($container, 'game.combat.fighter.minimum_damage'),
            self::intParameter($container, 'game.combat.fighter.max_turns'),
        );
    }

    private static function shippedCatalog(): EnemyCatalog
    {
        self::bootKernel();
        $container = self::getContainer();

        $enemies = $container->getParameter('game.combat.enemies');
        self::assertIsArray($enemies);

        /** @var list<array{key: string, level: int, hp: int, damage: int, mitigation_permille: int, extra_turn_permille: int}> $enemies */
        return new EnemyCatalog($enemies);
    }

    private static function intParameter(ContainerInterface $container, string $name): int
    {
        $value = $container->getParameter($name);
        self::assertIsInt($value);

        return $value;
    }
}
