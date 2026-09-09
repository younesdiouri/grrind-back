<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260908170000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ItemSalePriceMigrationTest extends KernelTestCase
{
    public function testMigrationInitializesOnceIncludingOddAndZeroPrices(): void
    {
        require_once \dirname(__DIR__, 2).'/migrations/Version20260908170000.php';
        $db = self::getContainer()->get(Connection::class);
        $db->beginTransaction();
        try {
            $db->executeStatement('CREATE TEMP TABLE game_item (id INT, price_coins INT) ON COMMIT DROP');
            $db->executeStatement('INSERT INTO game_item VALUES (1, 31), (2, 0), (3, 900)');
            $migration = new Version20260908170000($db, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $db->executeStatement($query->getStatement(), array_values($query->getParameters()));
            }
            self::assertSame([15, 0, 450], $db->fetchFirstColumn('SELECT sell_price_coins FROM game_item ORDER BY id'));
            $db->executeStatement('UPDATE game_item SET price_coins = 100 WHERE id = 1');
            self::assertSame(15, $db->fetchOne('SELECT sell_price_coins FROM game_item WHERE id = 1'));
        } finally {
            $db->rollBack();
        }
    }
}
