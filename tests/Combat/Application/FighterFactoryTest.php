<?php

declare(strict_types=1);

namespace App\Tests\Combat\Application;

use App\Combat\Application\FighterFactory;
use App\Combat\Domain\CombatRules;
use App\Combat\Domain\Enemy;
use App\Shared\Application\PlayerProgression;
use App\Shared\Domain\Activity\AttributeGains;
use App\Shared\Domain\Activity\VitalityBreakdown;
use PHPUnit\Framework\TestCase;

/**
 * La traduction caractéristique → combattant, seule et sans conteneur : voir le docblock de
 * {@see FighterFactory} pour la correspondance et pourquoi le socle + contribution est la
 * forme retenue. Ce que ces tests démontrent est indépendant des valeurs livrées dans
 * `combat.yaml` — {@see \App\Tests\Shared\Config\CombatCoverageTest} vérifie celles-là.
 */
final class FighterFactoryTest extends TestCase
{
    public function testAFreshAccountIsPlayableAtTheFighterSocle(): void
    {
        $factory = self::factoryOf();

        $fighter = $factory->forPlayer(PlayerProgression::untouched());

        // Les quatre caractéristiques et la Vitality valent zéro pour un compte neuf : le
        // combattant produit est exactement le socle, pas un combattant à zéro point de vie.
        self::assertSame(100, $fighter->hp);
        self::assertSame(10, $fighter->damage);
        self::assertSame(0, $fighter->mitigationPermille);
        self::assertSame(0, $fighter->extraTurnPermille);
        self::assertSame(0, $fighter->dodgePermille);
    }

    public function testMoreVitalityYieldsMoreHpEverythingElseEqual(): void
    {
        $factory = self::factoryOf();

        $low = $factory->forPlayer(self::progressionOf(vitality: 1_000));
        $high = $factory->forPlayer(self::progressionOf(vitality: 5_000));

        self::assertGreaterThan($low->hp, $high->hp);
    }

    public function testMoreStrengthYieldsMoreDamageEverythingElseEqual(): void
    {
        $factory = self::factoryOf();

        $low = $factory->forPlayer(self::progressionOf(strength: 1_000));
        $high = $factory->forPlayer(self::progressionOf(strength: 5_000));

        self::assertGreaterThan($low->damage, $high->damage);
    }

    public function testMoreEnduranceYieldsMoreMitigationEverythingElseEqual(): void
    {
        $factory = self::factoryOf();

        $low = $factory->forPlayer(self::progressionOf(endurance: 1_000));
        $high = $factory->forPlayer(self::progressionOf(endurance: 5_000));

        self::assertGreaterThan($low->mitigationPermille, $high->mitigationPermille);
    }

    public function testMoreDexterityYieldsMoreExtraTurnChanceEverythingElseEqual(): void
    {
        $factory = self::factoryOf();

        $low = $factory->forPlayer(self::progressionOf(dexterity: 1_000));
        $high = $factory->forPlayer(self::progressionOf(dexterity: 5_000));

        self::assertGreaterThan($low->extraTurnPermille, $high->extraTurnPermille);
    }

    /**
     * `Mobility` entre en combat au #218 — voir le docblock de {@see FighterFactory}. Ce
     * test était l'inverse jusque-là (#210) : il prouvait que la caractéristique ne changeait
     * rien au `Fighter` produit, pour qu'elle ne soit pas branchée par distraction avant
     * qu'on ait décidé à quoi. La décision est prise ; c'est maintenant l'inverse qui doit
     * être vrai, et cette classe reste le seul endroit qui en parle.
     */
    public function testMobilityChangesTheFighterProduced(): void
    {
        $factory = self::factoryOf();

        $withoutMobility = $factory->forPlayer(self::progressionOf(strength: 2_000, endurance: 2_000, dexterity: 2_000, mobility: 0));
        $withMobility = $factory->forPlayer(self::progressionOf(strength: 2_000, endurance: 2_000, dexterity: 2_000, mobility: 1_000_000));

        self::assertNotEquals($withoutMobility, $withMobility);
        self::assertGreaterThan($withoutMobility->dodgePermille, $withMobility->dodgePermille);
    }

    public function testMoreMobilityYieldsMoreDodgeChanceEverythingElseEqual(): void
    {
        $factory = self::factoryOf();

        $low = $factory->forPlayer(self::progressionOf(mobility: 1_000));
        $high = $factory->forPlayer(self::progressionOf(mobility: 5_000));

        self::assertGreaterThan($low->dodgePermille, $high->dodgePermille);
    }

    public function testDodgeChanceNeverExceedsTheConfiguredCap(): void
    {
        $factory = self::factoryOf(dodgeCapPermille: 300);

        $fighter = $factory->forPlayer(self::progressionOf(mobility: 1_000_000));

        self::assertSame(300, $fighter->dodgePermille);
    }

    public function testMitigationNeverExceedsTheConfiguredCap(): void
    {
        $factory = self::factoryOf(mitigationCapPermille: 700);

        $fighter = $factory->forPlayer(self::progressionOf(endurance: 1_000_000));

        self::assertSame(700, $fighter->mitigationPermille);
    }

    public function testExtraTurnChanceNeverExceedsTheConfiguredCap(): void
    {
        $factory = self::factoryOf(extraTurnCapPermille: 350);

        $fighter = $factory->forPlayer(self::progressionOf(dexterity: 1_000_000));

        self::assertSame(350, $fighter->extraTurnPermille);
    }

    /**
     * Le catalogue écrit déjà des valeurs de combattant : `forEnemy()` ne dérive rien, il
     * transporte — voir le docblock d'`Enemy`.
     */
    public function testAnEnemyMapsDirectlyOntoAFighter(): void
    {
        $factory = self::factoryOf();

        $enemy = new Enemy(key: 'SAND_JACKAL', level: 1, hp: 120, damage: 12, mitigationPermille: 50, extraTurnPermille: 40, dodgePermille: 30);

        $fighter = $factory->forEnemy($enemy);

        self::assertSame(120, $fighter->hp);
        self::assertSame(12, $fighter->damage);
        self::assertSame(50, $fighter->mitigationPermille);
        self::assertSame(40, $fighter->extraTurnPermille);
        self::assertSame(30, $fighter->dodgePermille);
    }

    private static function factoryOf(
        int $mitigationCapPermille = 700,
        int $extraTurnCapPermille = 350,
        int $dodgeCapPermille = 300,
    ): FighterFactory {
        return new FighterFactory(new CombatRules(
            baseHp: 100,
            hpPer1000Vitality: 40,
            baseDamage: 10,
            damagePer1000Strength: 6,
            mitigationPermillePer1000Endurance: 15,
            mitigationCapPermille: $mitigationCapPermille,
            extraTurnPermillePer1000Dexterity: 12,
            extraTurnCapPermille: $extraTurnCapPermille,
            dodgePermillePer1000Mobility: 10,
            dodgeCapPermille: $dodgeCapPermille,
            minimumDamage: 1,
            maxTurns: 200,
        ));
    }

    private static function progressionOf(
        int $strength = 0,
        int $endurance = 0,
        int $mobility = 0,
        int $dexterity = 0,
        int $vitality = 0,
    ): PlayerProgression {
        return new PlayerProgression(
            level: 1,
            xpIntoLevel: 0,
            xpToNextLevel: null,
            title: null,
            attributes: new AttributeGains($strength, $endurance, $mobility, $dexterity),
            vitality: $vitality,
            vitalityBreakdown: new VitalityBreakdown(0, 1, 0),
        );
    }
}
