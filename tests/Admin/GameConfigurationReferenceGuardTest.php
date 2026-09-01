<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Domain\GameEnemy;
use App\Admin\Domain\GameItem;
use App\Admin\Domain\GameLootTable;
use App\Admin\Domain\GameTitle;
use App\Admin\Infrastructure\GameConfigurationReferenceGuard;
use Closure;
use Doctrine\DBAL\Connection;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Une suppression physique ne contourne jamais un fait historique ou une référence de config. */
final class GameConfigurationReferenceGuardTest extends TestCase
{
    /** @param Closure(): object $configuration */
    #[DataProvider('configurations')]
    public function testUnusedConfigurationCanBeDeleted(Closure $configuration, int $referenceQueries): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly($referenceQueries))->method('fetchOne')->willReturn(false);

        new GameConfigurationReferenceGuard($connection)->assertDeletable($configuration());
    }

    /** @param Closure(): object $configuration */
    #[DataProvider('configurations')]
    public function testFirstConfigurationOrHistoricalReferenceRefusesDeletion(Closure $configuration, int $referenceQueries): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchOne')->willReturn('used');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Suppression refusée');
        new GameConfigurationReferenceGuard($connection)->assertDeletable($configuration());
    }

    /** @return iterable<string, array{Closure(): object, int}> */
    public static function configurations(): iterable
    {
        yield 'item, inventaire/loot/config/bataille' => [static function (): GameItem {
            $item = new GameItem();
            $item->setKey('FREE_ITEM');
            $item->setActive(false);

            return $item;
        }, 4];
        yield 'titre, déblocage/sélection' => [static function (): GameTitle {
            $title = new GameTitle();
            $title->setKey('FREE_TITLE');
            $title->setActive(false);

            return $title;
        }, 2];
        yield 'ennemi, bataille/table adversary' => [static function (): GameEnemy {
            $enemy = new GameEnemy();
            $enemy->setKey('FREE_ENEMY');
            $enemy->setActive(false);

            return $enemy;
        }, 2];
        yield 'table, audit de tirage' => [static function (): GameLootTable {
            $table = new GameLootTable();
            $table->setKind('workout');
            $table->setKey('FREE_TABLE');
            $table->setActive(false);

            return $table;
        }, 1];
    }

    public function testActiveConfigurationMustBeDeactivatedBeforePhysicalDeletion(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');
        $item = new GameItem();
        $item->setKey('PUBLISHED_ITEM');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('désactivez d’abord');
        new GameConfigurationReferenceGuard($connection)->assertDeletable($item);
    }
}
