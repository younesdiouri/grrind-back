<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Domain\GameItem;
use App\Admin\Domain\GameLootTable;
use App\Admin\Domain\GameRuleset;
use App\Admin\Domain\GameSettings;
use App\Admin\Infrastructure\GameRulesetPublisher;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/** Le hash métier et la version de loot ne changent qu'avec les données qui les concernent. */
final class GameRulesetPublisherVersionTest extends ApiTestCase
{
    public function testPresentationAndNoopPublicationKeepTheGameplayAndLootVersions(): void
    {
        $manager = $this->manager();
        $publisher = self::getContainer()->get(GameRulesetPublisher::class);
        self::assertInstanceOf(GameRulesetPublisher::class, $publisher);
        $item = $manager->getRepository(GameItem::class)->findOneBy([]);
        $ruleset = $manager->find(GameRuleset::class, 1);
        $settings = $manager->find(GameSettings::class, 1);
        self::assertInstanceOf(GameItem::class, $item);
        self::assertInstanceOf(GameRuleset::class, $ruleset);
        self::assertInstanceOf(GameSettings::class, $settings);
        $image = $item->getImagePath();
        $version = $ruleset->version();
        $lootVersion = $settings->lootVersion();

        try {
            $item->setImagePath('presentation-only.png');
            $this->publish($publisher, $manager);
            $manager->clear();
            $published = $manager->find(GameRuleset::class, 1);
            $afterPresentation = $manager->find(GameSettings::class, 1);
            self::assertInstanceOf(GameRuleset::class, $published);
            self::assertInstanceOf(GameSettings::class, $afterPresentation);
            self::assertSame($version, $published->version());
            self::assertSame($lootVersion, $afterPresentation->lootVersion());

            $this->publish($publisher, $manager);
            $manager->clear();
            $afterNoop = $manager->find(GameSettings::class, 1);
            self::assertInstanceOf(GameSettings::class, $afterNoop);
            self::assertSame($lootVersion, $afterNoop->lootVersion());
        } finally {
            $manager->clear();
            $original = $manager->getRepository(GameItem::class)->find($item->getId());
            if ($original instanceof GameItem) {
                $original->setImagePath($image);
                $this->publish($publisher, $manager);
            }
        }
    }

    public function testLootGameplayChangeIncrementsThePersistedLootVersion(): void
    {
        $manager = $this->manager();
        $publisher = self::getContainer()->get(GameRulesetPublisher::class);
        self::assertInstanceOf(GameRulesetPublisher::class, $publisher);
        $table = $manager->getRepository(GameLootTable::class)->findOneBy([]);
        $settings = $manager->find(GameSettings::class, 1);
        self::assertInstanceOf(GameLootTable::class, $table);
        self::assertInstanceOf(GameSettings::class, $settings);
        $entries = $table->getEntries();
        self::assertNotEmpty($entries);
        $before = $settings->lootVersion();

        try {
            $changed = $entries;
            ++$changed[0]['weight'];
            $table->setEntries($changed);
            $this->publish($publisher, $manager);
            $manager->clear();
            $after = $manager->find(GameSettings::class, 1);
            self::assertInstanceOf(GameSettings::class, $after);
            self::assertSame($before + 1, $after->lootVersion());
        } finally {
            $manager->clear();
            $original = $manager->getRepository(GameLootTable::class)->find($table->getId());
            if ($original instanceof GameLootTable) {
                $original->setEntries($entries);
                $this->publish($publisher, $manager);
            }
        }
    }

    private function publish(GameRulesetPublisher $publisher, EntityManagerInterface $manager): void
    {
        $manager->wrapInTransaction(static function () use ($publisher, $manager): void {
            $publisher->publish($manager);
        });
        $publisher->invalidateAfterCommit();
    }

    private function manager(): EntityManagerInterface
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }
}
