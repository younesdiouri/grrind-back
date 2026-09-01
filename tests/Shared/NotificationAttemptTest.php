<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Shared\Domain\Notification\NotificationAttempt;
use App\Shared\Infrastructure\Doctrine\NotificationAttemptRepository;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Uid\Uuid;

final class NotificationAttemptTest extends ApiTestCase
{
    /**
     * Une catégorie retirée reste une trace d'audit : la colonne doit donc s'hydrater comme
     * une chaîne, pas comme une enum qui ne connaît plus cette valeur.
     */
    public function testHydratesAnHistoricalCategoryThatIsNoLongerLive(): void
    {
        $id = Uuid::v7();

        $this->connection()->insert('shared_notification_attempt', [
            'id' => $id->toRfc4122(),
            'event_id' => Uuid::v7()->toRfc4122(),
            'recipient_id' => Uuid::v7()->toRfc4122(),
            'category' => 'RETIRED_CATEGORY',
            'created_at' => new DateTimeImmutable(),
        ], ['created_at' => Types::DATETIMETZ_IMMUTABLE]);

        $attempt = $this->attempts()->find($id);

        self::assertInstanceOf(NotificationAttempt::class, $attempt);
        self::assertSame('RETIRED_CATEGORY', $attempt->category());
    }

    private function attempts(): NotificationAttemptRepository
    {
        $repository = self::getContainer()->get(NotificationAttemptRepository::class);
        self::assertInstanceOf(NotificationAttemptRepository::class, $repository);

        return $repository;
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
