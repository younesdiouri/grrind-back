<?php

declare(strict_types=1);

namespace App\Tests\Rewards;

use App\Rewards\Domain\AlamGrant;
use App\Rewards\Infrastructure\Doctrine\InventoryItemRepository;
use App\Shared\Application\AlamRewards;
use App\Shared\Application\FrozenGameRulesets;
use App\Shared\Application\GameRulesets;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\Uid\Uuid;

final class AlamRewardsTest extends ApiTestCase
{
    public function testGrantUsesFrozenCatalogueAndUniqueEncounterProvenance(): void
    {
        $bob = $this->openAccount();
        $run = Uuid::v7();
        $snapshot = self::getContainer()->get(GameRulesets::class)->snapshot();
        self::assertIsArray($snapshot['items']);
        $snapshot['items'][] = ['key' => 'FROZEN_DUST', 'kind' => 'RESOURCE', 'rarity' => 'COMMON', 'price_coins' => 0, 'modifiers' => []];
        $frozen = new FrozenGameRulesets($snapshot, 'frozen-before-publication');
        $rewards = self::getContainer()->get(AlamRewards::class);
        $now = new DateTimeImmutable();
        $rewards->grant($run, 1, $bob->id, ['FROZEN_DUST' => 3], $now, $frozen);
        $rewards->grant($run, 1, $bob->id, ['FROZEN_DUST' => 3], $now, $frozen);
        $rewards->grant($run, 2, $bob->id, ['FROZEN_DUST' => 2], $now, $frozen);
        self::assertSame(5, self::getContainer()->get(InventoryItemRepository::class)->ofPlayerAndItem($bob->id, 'FROZEN_DUST')?->quantity());
        self::assertSame(2, self::getContainer()->get(EntityManagerInterface::class)->getRepository(AlamGrant::class)->count(['userId' => $bob->id]));
    }

    public function testInvalidSecondDropRollsBackAllDropsAndProvenance(): void
    {
        $bob = $this->openAccount();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        try {
            self::getContainer()->get(AlamRewards::class)->grant(Uuid::v7(), 1, $bob->id, ['NAFS_ESSENCE' => 3, 'UNKNOWN' => 1], new DateTimeImmutable(), self::getContainer()->get(GameRulesets::class));
            self::fail('Le second objet est inconnu.');
        } catch (LogicException) {
            self::assertSame(0, $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM rewards_inventory_item WHERE user_id = ?', [$bob->id->toRfc4122()]));
            self::assertSame(0, $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM rewards_alam_grant'));
        }
    }
}
