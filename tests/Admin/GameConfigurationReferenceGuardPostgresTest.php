<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Domain\GameDiscipline;
use App\Admin\Domain\GameItem;
use App\Admin\Infrastructure\GameConfigurationReferenceGuard;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Les opérateurs JSONB des gardes sont exécutés contre PostgreSQL, pas seulement mockés. */
final class GameConfigurationReferenceGuardPostgresTest extends KernelTestCase
{
    public function testDisciplineMutationGuardUsesTheCurrentRisalaSchemaOnPostgreSql(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $manager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $discipline = $manager->getRepository(GameDiscipline::class)->findOneBy(['active' => true]);
        self::assertInstanceOf(GameDiscipline::class, $discipline);

        $connection->beginTransaction();
        try {
            $discipline->setActive(false);
            new GameConfigurationReferenceGuard($connection)->lockForMutation($discipline);
            self::addToAssertionCount(1);
        } finally {
            $connection->rollBack();
            $manager->clear();
        }
    }

    public function testFreeItemPassesEveryHistoricalJsonbGuardOnPostgreSql(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $manager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $item = new GameItem();
        $item->setKey('FREE_POSTGRES_GUARD_'.bin2hex(random_bytes(6)));
        $item->setActive(false);
        $item->setSortOrder(random_int(7_000_000, 7_999_999));
        $manager->persist($item);
        $manager->flush();
        try {
            new GameConfigurationReferenceGuard($connection)->assertDeletable($item);
            self::addToAssertionCount(1);
        } finally {
            $manager->remove($item);
            $manager->flush();
        }
    }
}
