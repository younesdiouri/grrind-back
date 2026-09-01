<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Domain\GameItem;
use App\Admin\Domain\GameRuleset;
use App\Admin\Infrastructure\GameConfigurationReferenceGuard;
use App\Admin\Infrastructure\GameRulesetPublisher;
use App\Admin\UI\EasyAdmin\GameCrudController;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** Un candidat refusé ne publie ni ses données ni une nouvelle révision. */
final class GameCrudRollbackTest extends ApiTestCase
{
    public function testInvalidEditRollsBackTheEntityAndThePublishedRevision(): void
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $item = $manager->getRepository(GameItem::class)->findOneBy([]);
        self::assertInstanceOf(GameItem::class, $item);
        $ruleset = $manager->find(GameRuleset::class, 1);
        self::assertInstanceOf(GameRuleset::class, $ruleset);
        $revision = $ruleset->revision();
        $price = $item->getPriceCoins();

        $item->setPriceCoins(-1);
        $controller = new RollbackItemCrudController(
            self::getContainer()->get(GameRulesetPublisher::class),
            self::getContainer()->get(GameConfigurationReferenceGuard::class),
            sys_get_temp_dir(),
        );

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('le prix en pièces ne peut pas être négatif');
        try {
            $controller->updateEntity($manager, $item);
        } finally {
            $manager->clear();
            $stored = $manager->find(GameItem::class, $item->getId());
            self::assertInstanceOf(GameItem::class, $stored);
            self::assertSame($price, $stored->getPriceCoins());
            $published = $manager->find(GameRuleset::class, 1);
            self::assertInstanceOf(GameRuleset::class, $published);
            self::assertSame($revision, $published->revision());
        }
    }
}

final class RollbackItemCrudController extends GameCrudController
{
    public static function getEntityFqcn(): string
    {
        return GameItem::class;
    }
}
