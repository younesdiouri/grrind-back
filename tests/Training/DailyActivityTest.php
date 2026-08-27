<?php

declare(strict_types=1);

namespace App\Tests\Training;

use App\Tests\Support\ApiTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;

/**
 * `PUT /api/daily-activity` — la moitié « sédentarité » de Vitality (#165), en dehors de la
 * synchro d'import.
 *
 * Ce qui compte le plus ici : une journée envoyée deux fois **révise**, elle ne double pas
 * — voir {@see self::testResendingTheSameDayRevisesItInsteadOfDuplicatingIt()}. C'est tout
 * l'intérêt de l'`UPSERT (user, jour)` sur lequel le ticket est tranché.
 */
final class DailyActivityTest extends ApiTestCase
{
    public function testUpsertsABatchOfDays(): void
    {
        $bob = $this->openAccount();

        $response = $this->send('PUT', '/api/daily-activity', [
            'days' => [
                ['day' => '2026-08-20', 'activeEnergyKcal' => 350, 'source' => 'APPLE_HEALTH'],
                ['day' => '2026-08-21', 'activeEnergyKcal' => 480, 'source' => 'APPLE_HEALTH'],
            ],
        ], $bob->headers);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('', (string) $response->getContent());
    }

    /**
     * Le cœur du ticket : 4 000 kcal envoyées à 14 h puis 11 000 à 22 h pour le même jour ne
     * sont pas deux journées, c'est la même relue plus tard. La seconde valeur doit gagner.
     */
    public function testResendingTheSameDayRevisesItInsteadOfDuplicatingIt(): void
    {
        $bob = $this->openAccount();

        $this->send('PUT', '/api/daily-activity', [
            'days' => [['day' => '2026-08-20', 'activeEnergyKcal' => 350, 'source' => 'APPLE_HEALTH']],
        ], $bob->headers);

        $second = $this->send('PUT', '/api/daily-activity', [
            'days' => [['day' => '2026-08-20', 'activeEnergyKcal' => 900, 'source' => 'APPLE_HEALTH']],
        ], $bob->headers);

        self::assertSame(Response::HTTP_NO_CONTENT, $second->getStatusCode());

        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        $rows = $connection->fetchAllAssociative(
            'SELECT active_energy_kcal FROM daily_activity WHERE user_id = :userId',
            ['userId' => $bob->id->toRfc4122()],
        );

        self::assertCount(1, $rows, 'La révision d\'un jour ne doit pas créer une seconde ligne.');
        self::assertSame(900, $rows[0]['active_energy_kcal']);
    }

    public function testRequiresAToken(): void
    {
        $response = $this->send('PUT', '/api/daily-activity', [
            'days' => [['day' => '2026-08-20', 'activeEnergyKcal' => 350, 'source' => 'APPLE_HEALTH']],
        ]);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testRejectsAnEmptyBatch(): void
    {
        $bob = $this->openAccount();

        $response = $this->send('PUT', '/api/daily-activity', ['days' => []], $bob->headers);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function testRejectsAnUnknownSource(): void
    {
        $bob = $this->openAccount();

        $response = $this->send('PUT', '/api/daily-activity', [
            'days' => [['day' => '2026-08-20', 'activeEnergyKcal' => 350, 'source' => 'STRAVA']],
        ], $bob->headers);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function testRejectsANegativeActiveEnergy(): void
    {
        $bob = $this->openAccount();

        $response = $this->send('PUT', '/api/daily-activity', [
            'days' => [['day' => '2026-08-20', 'activeEnergyKcal' => -1, 'source' => 'APPLE_HEALTH']],
        ], $bob->headers);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }
}
