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
     * Les trois disciplines du #166, sur les deux fournisseurs — c'est précisément ce que
     * `ActivityTypesSection` refuse de démarrer sans, donc les deux côtés se prouvent
     * ensemble plutôt que dans deux tests qui pourraient diverger.
     *
     * @return iterable<string, array{string, string, Discipline}>
     */
    public static function theThreeSportsThatFeedDexterity(): iterable
    {
        yield 'football, Apple, ballon au pied' => ['APPLE_HEALTH', 'soccer', Discipline::Football];
        yield 'football, Apple, rugby' => ['APPLE_HEALTH', 'rugby', Discipline::Football];
        yield 'football, Apple, américain' => ['APPLE_HEALTH', 'americanFootball', Discipline::Football];
        yield 'football, Apple, australien' => ['APPLE_HEALTH', 'australianFootball', Discipline::Football];
        yield 'football, Google, ballon au pied' => ['HEALTH_CONNECT', 'EXERCISE_TYPE_SOCCER', Discipline::Football];
        yield 'football, Google, rugby' => ['HEALTH_CONNECT', 'EXERCISE_TYPE_RUGBY', Discipline::Football];
        yield 'football, Google, américain' => ['HEALTH_CONNECT', 'EXERCISE_TYPE_FOOTBALL_AMERICAN', Discipline::Football];
        yield 'football, Google, australien' => ['HEALTH_CONNECT', 'EXERCISE_TYPE_FOOTBALL_AUSTRALIAN', Discipline::Football];

        yield 'sport de salle, Apple, basket' => ['APPLE_HEALTH', 'basketball', Discipline::CourtSports];
        yield 'sport de salle, Apple, hand' => ['APPLE_HEALTH', 'handball', Discipline::CourtSports];
        yield 'sport de salle, Apple, volley' => ['APPLE_HEALTH', 'volleyball', Discipline::CourtSports];
        yield 'sport de salle, Google, basket' => ['HEALTH_CONNECT', 'EXERCISE_TYPE_BASKETBALL', Discipline::CourtSports];
        yield 'sport de salle, Google, hand' => ['HEALTH_CONNECT', 'EXERCISE_TYPE_HANDBALL', Discipline::CourtSports];
        yield 'sport de salle, Google, volley' => ['HEALTH_CONNECT', 'EXERCISE_TYPE_VOLLEYBALL', Discipline::CourtSports];

        yield 'raquette, Apple, tennis' => ['APPLE_HEALTH', 'tennis', Discipline::RacketSports];
        yield 'raquette, Apple, badminton' => ['APPLE_HEALTH', 'badminton', Discipline::RacketSports];
        yield 'raquette, Apple, tennis de table' => ['APPLE_HEALTH', 'tableTennis', Discipline::RacketSports];
        yield 'raquette, Apple, squash' => ['APPLE_HEALTH', 'squash', Discipline::RacketSports];
        yield 'raquette, Apple, racquetball' => ['APPLE_HEALTH', 'racquetball', Discipline::RacketSports];
        yield 'raquette, Apple, pickleball' => ['APPLE_HEALTH', 'pickleball', Discipline::RacketSports];
        yield 'raquette, Google, tennis' => ['HEALTH_CONNECT', 'EXERCISE_TYPE_TENNIS', Discipline::RacketSports];
        yield 'raquette, Google, badminton' => ['HEALTH_CONNECT', 'EXERCISE_TYPE_BADMINTON', Discipline::RacketSports];
        yield 'raquette, Google, tennis de table' => ['HEALTH_CONNECT', 'EXERCISE_TYPE_TABLE_TENNIS', Discipline::RacketSports];
        yield 'raquette, Google, squash' => ['HEALTH_CONNECT', 'EXERCISE_TYPE_SQUASH', Discipline::RacketSports];
        yield 'raquette, Google, racquetball' => ['HEALTH_CONNECT', 'EXERCISE_TYPE_RACQUETBALL', Discipline::RacketSports];
    }

    #[DataProvider('theThreeSportsThatFeedDexterity')]
    public function testTheThreeNewSportsAreTranslatedOnBothProviders(string $source, string $activityType, Discipline $expected): void
    {
        self::assertSame($expected, self::shippedMap()->disciplineFor($source, $activityType));
    }

    /**
     * **Le piège du ticket.** `paddleSports` est le kayak et le canoë — la pagaie, pas la
     * raquette. Le mapper sous `RACKET_SPORTS` enverrait toutes les descentes de rivière
     * du pays sur la Dexterity ; il reste donc non traduit, comme avant ce ticket. Même
     * vigilance côté Health Connect avec `EXERCISE_TYPE_PADDLING`.
     */
    public function testPaddleSportsIsKayakingNotPadelAndStaysUntranslated(): void
    {
        $map = self::shippedMap();

        self::assertNull($map->disciplineFor('APPLE_HEALTH', 'paddleSports'));
        self::assertNull($map->disciplineFor('HEALTH_CONNECT', 'EXERCISE_TYPE_PADDLING'));
    }

    /**
     * Le type que l'Apple Watch écrit pour « Cardio varié », et le repli de plusieurs
     * appareils de salle.
     *
     * Il n'était pas dans la table, et il valait onze des douze séances de la première
     * synchronisation réelle depuis un iPhone (#110) : le back les a toutes écartées, à
     * raison, et le joueur n'avait rien à jouer. Il se range avec `crossTraining`, qui
     * est la même idée sous un autre nom.
     */
    public function testMixedCardioIsPlayed(): void
    {
        self::assertSame(Discipline::Hiit, self::shippedMap()->disciplineFor('APPLE_HEALTH', 'mixedCardio'));
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
