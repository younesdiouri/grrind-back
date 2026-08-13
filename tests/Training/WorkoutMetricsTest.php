<?php

declare(strict_types=1);

namespace App\Tests\Training;

use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Workouts;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Les mesures rapportées par la montre, et l'unicité qui empêche le double crédit.
 *
 * Ces tests attaquent la **base**, pas les routes, et c'est délibéré : l'import qui
 * remplira ces colonnes n'existe pas encore (#88), et surtout ce qu'on vérifie ici est
 * une garantie de PostgreSQL. Un contrôle applicatif laisserait passer deux
 * synchronisations concurrentes entre son SELECT et son INSERT ; l'index, non. Le
 * tester par le code testerait le mauvais mécanisme.
 */
final class WorkoutMetricsTest extends ApiTestCase
{
    use Workouts;

    /**
     * L'absence est « non mesuré », et elle se distingue du zéro : un tour de piste plat
     * a bien un dénivelé de zéro. Le calcul d'XP (#90) doit lire `null` comme « pas de
     * bonus », jamais comme une valeur nulle.
     */
    public function testAWorkoutWithoutMeasurementsStoresNullAndNotZero(): void
    {
        $bob = $this->openAccount();
        $session = $this->recordWorkout($bob);

        $row = $this->connection()->fetchAssociative(
            'SELECT distance_meters, calories, elevation_gain_meters, average_heart_rate, external_id
               FROM workout WHERE id = :id',
            ['id' => $session],
        );

        self::assertIsArray($row);
        self::assertSame(
            ['distance_meters' => null, 'calories' => null, 'elevation_gain_meters' => null, 'average_heart_rate' => null, 'external_id' => null],
            $row,
        );
    }

    /**
     * Le même workout envoyé deux fois par un client qui revient au premier plan : la
     * seconde écriture est refusée par la base, pas par une vérification qu'on aurait pu
     * oublier d'appeler.
     */
    public function testTheSameProviderWorkoutCannotBeStoredTwice(): void
    {
        $bob = $this->openAccount();
        $this->imported($bob, 'HEALTHKIT', 'HK-2026-08-12-001');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->imported($bob, 'HEALTHKIT', 'HK-2026-08-12-001');
    }

    /**
     * L'unicité est par joueur. Deux comptes peuvent porter le même identifiant sans
     * que ça veuille dire quoi que ce soit — les identifiants d'Apple sont uniques chez
     * Apple, pas chez nous.
     */
    public function testTwoPlayersMayCarryTheSameProviderIdentifier(): void
    {
        $bob = $this->openAccount();
        $alice = $this->openAccount('alice@grrind.app', 'Alice');

        $this->imported($bob, 'HEALTHKIT', 'HK-PARTAGE');
        $this->imported($alice, 'HEALTHKIT', 'HK-PARTAGE');

        self::assertSame(2, $this->countWorkoutsWithExternalId('HK-PARTAGE'));
    }

    /**
     * La même séance vue par Apple Health et par Health Connect reste **deux lignes** :
     * ce n'est pas un doublon mais un chevauchement, et il se tranche ailleurs (#91)
     * avec ce qu'il faut de contexte. L'unicité ne prétend pas arbitrer ça.
     */
    public function testTheSameIdentifierUnderAnotherSourceIsNotADuplicate(): void
    {
        $bob = $this->openAccount();

        $this->imported($bob, 'HEALTHKIT', 'SEANCE-DU-12');
        $this->imported($bob, 'STRAVA', 'SEANCE-DU-12');

        self::assertSame(2, $this->countWorkoutsWithExternalId('SEANCE-DU-12'));
    }

    /**
     * L'index est partiel, et il fallait qu'il le soit : PostgreSQL considère deux NULL
     * comme distincts, mais une contrainte totale sur trois colonnes dont une nullable
     * se comporte de façon surprenante dès qu'on la relit. Le `WHERE` dit ce qu'on
     * protège. Les séances sans identifiant fournisseur — le chronomètre, tant qu'il
     * existe — n'entrent pas dedans.
     */
    public function testWorkoutsWithoutAProviderIdentifierAreNotConstrained(): void
    {
        $bob = $this->openAccount();

        $this->imported($bob, 'MANUAL_TIMER', null);
        $this->imported($bob, 'MANUAL_TIMER', null);

        self::assertSame(2, $this->countWorkouts(
            'user_id = :userId AND external_id IS NULL',
            ['userId' => $bob->id->toRfc4122()],
        ));
    }

    /**
     * Écrit directement en base : la route d'import arrive au #88, et ce qui est en jeu
     * ici est la contrainte, qu'aucune route ne doit pouvoir contourner de toute façon.
     */
    private function imported(Account $account, string $source, ?string $externalId): void
    {
        $this->recordWorkout(
            $account,
            source: $source,
            externalId: $externalId,
            distanceMeters: 10500,
            calories: 620,
            elevationGainMeters: 84,
            averageHeartRate: 148,
        );
    }

    private function countWorkoutsWithExternalId(string $externalId): int
    {
        return $this->countWorkouts('external_id = :externalId', ['externalId' => $externalId]);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function countWorkouts(string $where, array $parameters): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM workout WHERE '.$where, $parameters);
        \assert(is_numeric($count));

        return (int) $count;
    }
}
