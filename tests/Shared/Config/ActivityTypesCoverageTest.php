<?php

declare(strict_types=1);

namespace App\Tests\Shared\Config;

use App\Shared\Domain\Activity\ActivityTypeMap;
use App\Shared\Domain\Activity\Discipline;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * La table livrée, celle de `config/game/v1/activity_types.yaml`, contre les vrais noms
 * de types des deux fournisseurs.
 *
 * `ActivityTypeMapTest` prouve que l'objet tient ses règles sur des données écrites pour
 * lui ; celui-ci prouve que **la table qu'on livre** traduit ce qu'une montre produit
 * réellement. Les deux sont nécessaires : le premier passerait sur une table vide.
 *
 * Les chaînes sont recopiées en dur, et c'est le point. Un test qui relirait le YAML qu'il
 * vérifie ne vérifierait rien — et ces chaînes-là sont un contrat avec Apple et Google,
 * pas un réglage : une casse qui diverge ne casse rien bruyamment, elle donne un import
 * silencieusement vide.
 */
final class ActivityTypesCoverageTest extends KernelTestCase
{
    /**
     * Les sept sports de la V1, tels qu'une montre les rapporte de chaque côté.
     *
     * @return iterable<string, array{string, string, Discipline}>
     */
    public static function theSevenSports(): iterable
    {
        yield 'course, Apple' => ['APPLE_HEALTH', 'running', Discipline::Running];
        yield 'course, Google' => ['HEALTH_CONNECT', 'EXERCISE_TYPE_RUNNING', Discipline::Running];
        yield 'marche, Apple' => ['APPLE_HEALTH', 'walking', Discipline::Walking];
        yield 'marche, Google' => ['HEALTH_CONNECT', 'EXERCISE_TYPE_WALKING', Discipline::Walking];
        yield 'vélo, Apple' => ['APPLE_HEALTH', 'cycling', Discipline::Cycling];
        yield 'vélo, Google' => ['HEALTH_CONNECT', 'EXERCISE_TYPE_BIKING', Discipline::Cycling];
        yield 'natation, Apple' => ['APPLE_HEALTH', 'swimming', Discipline::Swimming];
        yield 'natation, Google' => ['HEALTH_CONNECT', 'EXERCISE_TYPE_SWIMMING_POOL', Discipline::Swimming];
        yield 'renforcement, Apple' => ['APPLE_HEALTH', 'traditionalStrengthTraining', Discipline::Strength];
        yield 'renforcement, Google' => ['HEALTH_CONNECT', 'EXERCISE_TYPE_STRENGTH_TRAINING', Discipline::Strength];
        yield 'HIIT, Apple' => ['APPLE_HEALTH', 'highIntensityIntervalTraining', Discipline::Hiit];
        yield 'HIIT, Google' => ['HEALTH_CONNECT', 'EXERCISE_TYPE_HIGH_INTENSITY_INTERVAL_TRAINING', Discipline::Hiit];
        yield 'randonnée, Apple' => ['APPLE_HEALTH', 'hiking', Discipline::Hiking];
        yield 'randonnée, Google' => ['HEALTH_CONNECT', 'EXERCISE_TYPE_HIKING', Discipline::Hiking];
    }

    #[DataProvider('theSevenSports')]
    public function testTheV1SportsAreTranslatedOnBothProviders(string $source, string $activityType, Discipline $expected): void
    {
        self::assertSame($expected, self::shippedMap()->disciplineFor($source, $activityType));
    }

    /**
     * Conservées bien qu'absentes des sept sports : elles existent chez les deux
     * fournisseurs, et des titres du Lot 3 les citent nommément.
     */
    public function testMobilityAndClimbingSurvivedTheV1Cut(): void
    {
        $map = self::shippedMap();

        self::assertSame(Discipline::Mobility, $map->disciplineFor('APPLE_HEALTH', 'yoga'));
        self::assertSame(Discipline::Mobility, $map->disciplineFor('HEALTH_CONNECT', 'EXERCISE_TYPE_YOGA'));
        self::assertSame(Discipline::Climbing, $map->disciplineFor('APPLE_HEALTH', 'climbing'));
        self::assertSame(Discipline::Climbing, $map->disciplineFor('HEALTH_CONNECT', 'EXERCISE_TYPE_ROCK_CLIMBING'));
    }

    /**
     * Une activité que Grrind ne sait pas traduire ne casse rien : elle rend `null`, et
     * l'import la comptera et la nommera au joueur (#92).
     */
    public function testAnActivityWeDoNotPlayIsNotTranslated(): void
    {
        self::assertNull(self::shippedMap()->disciplineFor('APPLE_HEALTH', 'curling'));
    }

    /**
     * Construit depuis les paramètres du conteneur, exactement comme `services.yaml` le
     * fait : le service lui-même est retiré tant qu'aucun code ne le consomme — l'import
     * arrive au #88.
     */
    private static function shippedMap(): ActivityTypeMap
    {
        self::bootKernel();
        $container = self::getContainer();

        $appleHealth = $container->getParameter('game.activity_types.apple_health');
        $healthConnect = $container->getParameter('game.activity_types.health_connect');

        self::assertIsArray($appleHealth);
        self::assertIsArray($healthConnect);

        /** @var list<array{activity_type: string, discipline: string}> $appleHealth */
        /** @var list<array{activity_type: string, discipline: string}> $healthConnect */
        return new ActivityTypeMap($appleHealth, $healthConnect);
    }
}
