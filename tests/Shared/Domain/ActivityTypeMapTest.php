<?php

declare(strict_types=1);

namespace App\Tests\Shared\Domain;

use App\Shared\Domain\Activity\ActivityTypeMap;
use App\Shared\Domain\Activity\Discipline;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * La traduction du verdict de la montre. Sans infra : c'est du domaine pur, et les règles
 * de cohérence qu'elle porte sont celles qui feront échouer le démarrage.
 */
final class ActivityTypeMapTest extends TestCase
{
    public function testTranslatesAProviderTypeIntoADiscipline(): void
    {
        $map = self::completeMap();

        self::assertSame(Discipline::Running, $map->disciplineFor('APPLE_HEALTH', 'running'));
        self::assertSame(Discipline::Hiking, $map->disciplineFor('HEALTH_CONNECT', 'EXERCISE_TYPE_HIKING'));
    }

    /**
     * Plusieurs types pointent la même discipline, et c'est le cas nominal : Apple
     * distingue la musculation traditionnelle de la fonctionnelle, Grrind non.
     */
    public function testSeveralTypesMayLeadToTheSameDiscipline(): void
    {
        $map = self::completeMap();

        self::assertSame(Discipline::Strength, $map->disciplineFor('APPLE_HEALTH', 'traditionalStrengthTraining'));
        self::assertSame(Discipline::Strength, $map->disciplineFor('APPLE_HEALTH', 'functionalStrengthTraining'));
    }

    /**
     * Un fournisseur qui ajoute une activité n'est pas une panne du serveur. Lever ici
     * ferait échouer un lot de dix workouts pour une séance de curling ; l'import compte
     * et nomme celui-là, et crédite les neuf autres.
     */
    public function testAnUnknownTypeIsNullAndNotAnError(): void
    {
        $map = self::completeMap();

        self::assertNull($map->disciplineFor('APPLE_HEALTH', 'curling'));
        self::assertNull($map->disciplineFor('HEALTH_CONNECT', 'EXERCISE_TYPE_CURLING'));
    }

    /**
     * Les deux espaces de noms sont disjoints aujourd'hui et rien ne garantit qu'ils le
     * restent : un type d'une source ne doit pas se résoudre depuis l'autre.
     */
    public function testASourceNeverReadsTheOthersTable(): void
    {
        $map = self::completeMap();

        self::assertNull($map->disciplineFor('HEALTH_CONNECT', 'running'));
        self::assertNull($map->disciplineFor('APPLE_HEALTH', 'EXERCISE_TYPE_RUNNING'));
    }

    public function testAnUnknownSourceIsNullAndNotAnError(): void
    {
        self::assertNull(self::completeMap()->disciplineFor('STRAVA', 'running'));
    }

    /**
     * Une discipline qu'aucun type ne produit apparaîtrait dans le contrat, dans le
     * barème d'XP et dans les titres sans qu'un joueur puisse jamais l'atteindre. Le
     * démarrage échoue plutôt que de la laisser dormir.
     */
    public function testRefusesADisciplineNoProviderTypeLeadsTo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/CLIMBING/');

        $incomplete = array_values(array_filter(
            self::appleHealth(),
            static fn (array $mapping): bool => 'CLIMBING' !== $mapping['discipline'],
        ));

        new ActivityTypeMap($incomplete, self::healthConnect());
    }

    /**
     * Soit les deux lignes disent la même chose et l'une est du bruit, soit elles se
     * contredisent et la dernière gagne en silence. Les deux se corrigent avant le
     * démarrage.
     */
    public function testRefusesATypeDeclaredTwice(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/running/');

        new ActivityTypeMap(
            [...self::appleHealth(), ['activity_type' => 'running', 'discipline' => 'WALKING']],
            self::healthConnect(),
        );
    }

    public function testRefusesADisciplineThatDoesNotExist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/QUIDDITCH/');

        new ActivityTypeMap(
            [...self::appleHealth(), ['activity_type' => 'quidditch', 'discipline' => 'QUIDDITCH']],
            self::healthConnect(),
        );
    }

    private static function completeMap(): ActivityTypeMap
    {
        return new ActivityTypeMap(self::appleHealth(), self::healthConnect());
    }

    /**
     * Un jeu minimal écrit ici plutôt que relu depuis `activity_types.yaml` : un test qui
     * lit la configuration qu'il vérifie ne vérifie rien. Que la vraie table tienne les
     * mêmes règles est prouvé par la compilation du conteneur, et par
     * `ActivityTypesCoverageTest`.
     *
     * @return list<array{activity_type: string, discipline: string}>
     */
    private static function appleHealth(): array
    {
        return [
            ['activity_type' => 'running', 'discipline' => 'RUNNING'],
            ['activity_type' => 'walking', 'discipline' => 'WALKING'],
            ['activity_type' => 'cycling', 'discipline' => 'CYCLING'],
            ['activity_type' => 'swimming', 'discipline' => 'SWIMMING'],
            ['activity_type' => 'traditionalStrengthTraining', 'discipline' => 'STRENGTH'],
            ['activity_type' => 'functionalStrengthTraining', 'discipline' => 'STRENGTH'],
            ['activity_type' => 'highIntensityIntervalTraining', 'discipline' => 'HIIT'],
            ['activity_type' => 'hiking', 'discipline' => 'HIKING'],
            ['activity_type' => 'yoga', 'discipline' => 'MOBILITY'],
            ['activity_type' => 'climbing', 'discipline' => 'CLIMBING'],
        ];
    }

    /**
     * @return list<array{activity_type: string, discipline: string}>
     */
    private static function healthConnect(): array
    {
        return [
            ['activity_type' => 'EXERCISE_TYPE_RUNNING', 'discipline' => 'RUNNING'],
            ['activity_type' => 'EXERCISE_TYPE_WALKING', 'discipline' => 'WALKING'],
            ['activity_type' => 'EXERCISE_TYPE_BIKING', 'discipline' => 'CYCLING'],
            ['activity_type' => 'EXERCISE_TYPE_SWIMMING_POOL', 'discipline' => 'SWIMMING'],
            ['activity_type' => 'EXERCISE_TYPE_STRENGTH_TRAINING', 'discipline' => 'STRENGTH'],
            ['activity_type' => 'EXERCISE_TYPE_HIGH_INTENSITY_INTERVAL_TRAINING', 'discipline' => 'HIIT'],
            ['activity_type' => 'EXERCISE_TYPE_HIKING', 'discipline' => 'HIKING'],
            ['activity_type' => 'EXERCISE_TYPE_YOGA', 'discipline' => 'MOBILITY'],
            ['activity_type' => 'EXERCISE_TYPE_ROCK_CLIMBING', 'discipline' => 'CLIMBING'],
        ];
    }
}
