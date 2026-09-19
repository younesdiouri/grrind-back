<?php

declare(strict_types=1);

namespace App\Tests\Rewards;

use App\Admin\Domain\GameRecipe;
use App\Admin\Domain\GameRuleset;
use App\Admin\Infrastructure\GameRulesetPublisher;
use App\Shared\Infrastructure\Config\GameRulesetVersion;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CraftingPublicationTest extends KernelTestCase
{
    public function testRecipeCostsStayDraftUntilPublicationAndInvalidCostsCannotPublish(): void
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $manager->getConnection();
        $connection->beginTransaction();
        try {
            $recipe = $manager->getRepository(GameRecipe::class)->findOneBy(['key' => 'IRON_GAUNTLETS']);
            self::assertInstanceOf(GameRecipe::class, $recipe);
            $published = $manager->find(GameRuleset::class, 1);
            self::assertInstanceOf(GameRuleset::class, $published);
            $before = $published->snapshot();
            $recipe->setCosts([['item' => 'NAFS_ESSENCE', 'quantity' => 7]]);
            $manager->flush();
            self::assertSame($before, $published->snapshot());
            $publisher = self::getContainer()->get(GameRulesetPublisher::class);
            $publisher->publish($manager, 'crafting-test');
            $snapshot = $published->snapshot();
            self::assertIsArray($snapshot['recipes']);
            self::assertIsArray($snapshot['recipes'][0]);
            self::assertSame(['NAFS_ESSENCE' => 7], $snapshot['recipes'][0]['costs']);
            self::assertSame(GameRulesetVersion::of($snapshot), $published->version());
            $recipe->setCosts([['item' => 'NAFS_ESSENCE', 'quantity' => 0]]);
            $manager->flush();
            try {
                $publisher->publish($manager, 'invalid-crafting-test');
                self::fail('Un coût nul ne doit pas être publié.');
            } catch (InvalidArgumentException) {
                self::assertSame($snapshot, $published->snapshot());
            }
        } finally {
            $connection->rollBack();
            $manager->clear();
        }
    }
}
