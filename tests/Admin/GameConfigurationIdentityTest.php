<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Domain\GameEnemy;
use App\Admin\Domain\GameItem;
use App\Admin\Domain\GameLootTable;
use App\Admin\Domain\GameTitle;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Les champs désactivés dans le HTML sont doublés d’une garde côté serveur. */
final class GameConfigurationIdentityTest extends KernelTestCase
{
    public function testItemKeyCannotBeRenamedServerSide(): void
    {
        $item = $this->manager()->getRepository(GameItem::class)->findOneBy([]);
        self::assertInstanceOf(GameItem::class, $item);
        $item->setKey($item->getKey().'_RENAMED');

        $this->expectException(LogicException::class);
        $this->manager()->flush();
    }

    public function testTitleKeyCannotBeRenamedServerSide(): void
    {
        $title = $this->manager()->getRepository(GameTitle::class)->findOneBy([]);
        self::assertInstanceOf(GameTitle::class, $title);
        $title->setKey($title->getKey().'_RENAMED');

        $this->expectException(LogicException::class);
        $this->manager()->flush();
    }

    public function testEnemyKeyCannotBeRenamedServerSide(): void
    {
        $enemy = $this->manager()->getRepository(GameEnemy::class)->findOneBy([]);
        self::assertInstanceOf(GameEnemy::class, $enemy);
        $enemy->setKey($enemy->getKey().'_RENAMED');

        $this->expectException(LogicException::class);
        $this->manager()->flush();
    }

    public function testLootKindCannotBeRenamedServerSide(): void
    {
        $table = $this->manager()->getRepository(GameLootTable::class)->findOneBy([]);
        self::assertInstanceOf(GameLootTable::class, $table);
        $table->setKind('chest' === $table->getKind() ? 'workout' : 'chest');

        $this->expectException(LogicException::class);
        $this->manager()->flush();
    }

    public function testLootKeyCannotBeRenamedServerSide(): void
    {
        $table = $this->manager()->getRepository(GameLootTable::class)->findOneBy([]);
        self::assertInstanceOf(GameLootTable::class, $table);
        $table->setKey($table->getKey().'_RENAMED');

        $this->expectException(LogicException::class);
        $this->manager()->flush();
    }

    private function manager(): EntityManagerInterface
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }
}
