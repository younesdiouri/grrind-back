<?php

declare(strict_types=1);

namespace App\Tests\Rewards;

use App\Progression\Application\GrantXp;
use App\Progression\Application\GrantXpHandler;
use App\Progression\Domain\XpBreakdownLine;
use App\Progression\Domain\XpBreakdownSource;
use App\Rewards\Application\EquipItem;
use App\Rewards\Application\EquipItemHandler;
use App\Rewards\Domain\ItemCatalog;
use App\Rewards\Infrastructure\Doctrine\InventoryItemRepository;
use App\Shared\Domain\Activity\Discipline;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Le pendant XP du « test qui compte » : un objet portant un `XP_MULTIPLIER` change le
 * `breakdown` d'une séance (#29) — même moteur, même port, un autre consommateur.
 *
 * `WORN_RUNNING_SHOES` porte `{ type: XP_MULTIPLIER, value: 5, discipline: RUNNING }`
 * (`items.yaml`) : une ligne `ITEM` doit apparaître dans le détail d'une séance de course,
 * jamais dans celui d'une autre discipline.
 */
final class EquippedItemsChangeTheXpBreakdownTest extends ApiTestCase
{
    public function testAnEquippedXpMultiplierAddsAnItemLineToARunningSessionsBreakdown(): void
    {
        $player = $this->openAccount()->id;
        self::equip($player, 'WORN_RUNNING_SHOES');

        $granted = (self::grantXp())(new GrantXp($player, Uuid::v7(), Discipline::Running, 3600, new DateTimeImmutable()));

        $itemLines = array_values(array_filter(
            $granted->award->breakdown->lines,
            static fn (XpBreakdownLine $line): bool => XpBreakdownSource::Item === $line->source,
        ));

        self::assertNotEmpty($itemLines, 'L\'objet équipé doit produire une ligne ITEM dans le breakdown.');
        self::assertGreaterThan(0, $itemLines[0]->amount);
    }

    /** La portée par discipline se respecte : aucune ligne ITEM sur une séance de natation. */
    public function testAnEquippedRunningScopedMultiplierDoesNotApplyToAnotherDiscipline(): void
    {
        $player = $this->openAccount()->id;
        self::equip($player, 'WORN_RUNNING_SHOES');

        $granted = (self::grantXp())(new GrantXp($player, Uuid::v7(), Discipline::Swimming, 3600, new DateTimeImmutable()));

        $itemLines = array_filter(
            $granted->award->breakdown->lines,
            static fn (XpBreakdownLine $line): bool => XpBreakdownSource::Item === $line->source,
        );

        self::assertSame([], array_values($itemLines));
    }

    private static function equip(Uuid $userId, string $itemKey): void
    {
        $repository = self::getContainer()->get(InventoryItemRepository::class);
        self::assertInstanceOf(InventoryItemRepository::class, $repository);

        $items = self::getContainer()->getParameter('game.items.items');
        self::assertIsArray($items);
        /** @var list<array{key: string, rarity: string, slot: string, price_coins: int, modifiers: list<array{type: string, value: int, discipline?: string}>}> $items */
        $catalog = new ItemCatalog($items);

        $repository->grant($userId, $itemKey, Uuid::v7(), new DateTimeImmutable());

        $handler = new EquipItemHandler($repository, $catalog);
        $item = $catalog->find($itemKey);
        self::assertNotNull($item);
        self::assertNotNull($item->slot);
        ($handler)(new EquipItem($userId, $itemKey, $item->slot->value));
    }

    private static function grantXp(): GrantXpHandler
    {
        $handler = self::getContainer()->get(GrantXpHandler::class);
        self::assertInstanceOf(GrantXpHandler::class, $handler);

        return $handler;
    }
}
