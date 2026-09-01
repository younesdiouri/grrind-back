<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Domain\GameItem;
use App\Admin\Infrastructure\GameConfigurationReferenceGuard;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Les opérateurs JSONB des gardes sont exécutés contre PostgreSQL, pas seulement mockés. */
final class GameConfigurationReferenceGuardPostgresTest extends KernelTestCase
{
    public function testFreeItemPassesEveryHistoricalJsonbGuardOnPostgreSql(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $item = new GameItem();
        $item->setKey('FREE_POSTGRES_GUARD');

        new GameConfigurationReferenceGuard($connection)->assertDeletable($item);
        self::addToAssertionCount(1);
    }
}
