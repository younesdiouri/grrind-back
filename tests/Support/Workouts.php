<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Domain\Activity\WorkoutSource;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * De quoi écrire des tests sur des workouts avant que la route d'import existe (#88).
 *
 * Les workouts sont écrits **directement en base**. Ce n'est pas un contournement : le
 * chronomètre a disparu (#85) et rien ne les crée plus par HTTP, donc il n'y a pas de
 * « vraie route » à préférer. Les suites qui vérifieront le comportement de l'import
 * taperont sur l'import ; celles qui vérifient une lecture ou une contrainte de base ont
 * besoin de lignes, pas d'un parcours.
 *
 * Les bornes viennent de l'appelant, comme elles viendront de la montre : c'est le seul
 * endroit du projet où les dates ne sont pas celles du serveur, et c'est le nouveau
 * modèle, pas une facilité de test.
 *
 * @phpstan-require-extends ApiTestCase
 */
trait Workouts
{
    /**
     * Un workout terminé, daté par l'appelant. Les valeurs par défaut décrivent une
     * demi-heure de course finie il y a une heure — assez loin pour ne croiser aucune
     * fenêtre, assez récent pour rester dans l'historique.
     */
    protected function recordWorkout(
        Account $account,
        string $discipline = 'RUNNING',
        int $durationSeconds = 1800,
        ?DateTimeImmutable $endedAt = null,
        string $source = 'APPLE_HEALTH',
        ?string $externalId = null,
        ?int $distanceMeters = null,
        ?int $calories = null,
        ?int $elevationGainMeters = null,
        ?int $averageHeartRate = null,
    ): string {
        $endedAt ??= new DateTimeImmutable('-1 hour');
        $startedAt = $endedAt->modify(\sprintf('-%d seconds', $durationSeconds));
        $id = Uuid::v7()->toRfc4122();

        $this->connection()->executeStatement(
            'INSERT INTO workout (id, user_id, discipline, source, trust, started_at, ended_at, duration_seconds, created_at,
                                  distance_meters, calories, elevation_gain_meters, average_heart_rate, external_id)
             VALUES (:id, :userId, :discipline, :source, :trust, :startedAt, :endedAt, :durationSeconds, NOW(),
                     :distance, :calories, :elevation, :heartRate, :externalId)',
            [
                'id' => $id,
                'userId' => $account->id->toRfc4122(),
                'discipline' => $discipline,
                'source' => $source,
                // Le crédit se dérive de la source, ici comme dans l'agrégat : une fixture
                // qui poserait `trust` à la main pourrait écrire une ligne que le domaine
                // ne sait pas produire, et le test vérifierait alors un monde imaginaire.
                'trust' => WorkoutSource::from($source)->defaultTrust()->value,
                'startedAt' => $startedAt->format(DateTimeInterface::ATOM),
                'endedAt' => $endedAt->format(DateTimeInterface::ATOM),
                'durationSeconds' => $durationSeconds,
                'distance' => $distanceMeters,
                'calories' => $calories,
                'elevation' => $elevationGainMeters,
                'heartRate' => $averageHeartRate,
                'externalId' => $externalId,
            ],
        );

        return $id;
    }

    protected function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
