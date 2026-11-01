<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Domain\GameRuleset;
use App\Admin\Domain\GameSettings;
use App\Admin\Infrastructure\GameRulesetPublisher;
use App\Engagement\Domain\LeagueRules;
use App\Engagement\Domain\LeagueTier;
use App\Shared\Infrastructure\Config\GameRulesetVersion;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

final class LeagueSettingsTest extends ApiTestCase
{
    public function testLeagueBalanceIsPublishedVersionedAndValidated(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $settings = $em->find(GameSettings::class, 1);
        $published = $em->find(GameRuleset::class, 1);
        self::assertInstanceOf(GameSettings::class, $settings);
        self::assertInstanceOf(GameRuleset::class, $published);
        $publisher = self::getContainer()->get(GameRulesetPublisher::class);
        $original = $settings->getLeague();
        $initial = $published->snapshot();
        $initialVersion = $published->version();
        self::assertSame(GameRulesetVersion::of($initial), $initialVersion, 'La migration publie la même empreinte que le runtime.');
        try {
            $settings->setLeague([...$original, 'loot_luck_per_tier_percent' => 7]);
            $em->flush();
            self::assertSame($initial, $published->snapshot(), 'Un brouillon ne fuit pas au runtime.');
            $em->wrapInTransaction(static fn () => $publisher->publish($em, 'league-test'));
            $publisher->invalidateAfterCommit();
            self::assertNotSame($initialVersion, $published->version());
            self::assertSame(28, LeagueRules::fromSnapshot($published->snapshot())->lootLuck(LeagueTier::Diamond));
            self::assertSame(GameRulesetVersion::of($published->snapshot()), $published->version());

            $settings->setLeague([...$original, 'movement_percent' => 50]);
            $this->expectException(InvalidArgumentException::class);
            $publisher->prepare($em);
        } finally {
            $settings->setLeague($original);
            $em->wrapInTransaction(static fn () => $publisher->publish($em, 'league-test-restore'));
            $publisher->invalidateAfterCommit();
        }
    }
}
