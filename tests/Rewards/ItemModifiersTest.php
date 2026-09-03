<?php

declare(strict_types=1);

namespace App\Tests\Rewards;

use App\Rewards\Application\ItemModifiers;
use App\Rewards\Domain\EquipmentSlot;
use App\Rewards\Domain\ItemCatalog;
use App\Rewards\Infrastructure\Doctrine\InventoryItemRepository;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Modifier\Modifier;
use App\Shared\Domain\Modifier\ModifierSource;
use App\Shared\Domain\Modifier\ModifierType;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * `ItemModifiers` contre la vraie base et le vrai catalogue livré : c'est le premier
 * contributeur réel de `ModifierResolver` (#29), voir son docblock.
 *
 * Construite directement plutôt que résolue — voir le docblock d'`EquipItemHandlerTest` pour
 * pourquoi `ItemCatalog` n'est fetchable depuis aucun conteneur, test ou production.
 */
final class ItemModifiersTest extends ApiTestCase
{
    public function testAPlayerWithNothingEquippedContributesNothing(): void
    {
        $modifiers = self::itemModifiers()->modifiersOf(Uuid::v7(), new DateTimeImmutable());

        self::assertSame([], $modifiers);
    }

    /**
     * `WORN_RUNNING_SHOES` porte deux modificateurs dans le catalogue DB publié : un `XP_MULTIPLIER`
     * scopé à `RUNNING`, et un `MOBILITY_BONUS` global — les deux doivent traverser, chacun
     * avec sa portée d'origine et `ModifierSource::Item`.
     */
    public function testAnEquippedItemContributesEveryOneOfItsModifiersTaggedAsItem(): void
    {
        $userId = Uuid::v7();
        self::equip($userId, 'WORN_RUNNING_SHOES', EquipmentSlot::Feet);

        $modifiers = self::itemModifiers()->modifiersOf($userId, new DateTimeImmutable());

        self::assertEquals(
            [
                new Modifier(ModifierType::XpMultiplier, 5, ModifierSource::Item, Discipline::Running),
                new Modifier(ModifierType::MobilityBonus, 1000, ModifierSource::Item),
            ],
            $modifiers,
        );
    }

    /** Un objet dans le sac, jamais équipé, ne contribue rien. */
    public function testAnOwnedButUnequippedItemContributesNothing(): void
    {
        $userId = Uuid::v7();
        self::repository()->grant($userId, 'WORN_RUNNING_SHOES', Uuid::v7(), new DateTimeImmutable());

        $modifiers = self::itemModifiers()->modifiersOf($userId, new DateTimeImmutable());

        self::assertSame([], $modifiers);
    }

    public function testTwoEquippedItemsContributeTheModifiersOfBoth(): void
    {
        $userId = Uuid::v7();
        self::equip($userId, 'WORN_RUNNING_SHOES', EquipmentSlot::Feet);
        self::equip($userId, 'IRON_GAUNTLETS', EquipmentSlot::Hands);

        $modifiers = self::itemModifiers()->modifiersOf($userId, new DateTimeImmutable());

        self::assertCount(4, $modifiers, 'Deux modificateurs par objet, deux objets équipés.');
    }

    /**
     * Le pendant du #30 côté moteur : `PUT /api/inventory/equipment/{slot}` échange plutôt
     * que de refuser (voir `InventoryItemRepository::equip()`), et une fois l'échange fait,
     * `ModifierResolver` ne doit plus jamais rien voir de l'ancien occupant — sans quoi un
     * joueur qui change de bottes garderait le bonus des deux paires à la fois.
     */
    public function testSwappingEquipmentOnlyContributesTheNewItemsModifiers(): void
    {
        $userId = Uuid::v7();
        self::equip($userId, 'WORN_RUNNING_SHOES', EquipmentSlot::Feet);
        self::equip($userId, 'STORMCALLERS_BOOTS', EquipmentSlot::Feet);

        $modifiers = self::itemModifiers()->modifiersOf($userId, new DateTimeImmutable());

        self::assertEquals(
            [
                new Modifier(ModifierType::XpMultiplier, 18, ModifierSource::Item, Discipline::Running),
                new Modifier(ModifierType::MobilityBonus, 4200, ModifierSource::Item),
            ],
            $modifiers,
        );
    }

    /**
     * Voir le docblock d'`ItemModifiers` : ce paramètre est délibérément ignoré, un objet
     * équipé n'a pas de fenêtre de validité contrairement à un bonus de guilde.
     */
    public function testTheOccurredAtParameterHasNoEffect(): void
    {
        $userId = Uuid::v7();
        self::equip($userId, 'WORN_RUNNING_SHOES', EquipmentSlot::Feet);

        $modifiers = self::itemModifiers();

        self::assertEquals(
            $modifiers->modifiersOf($userId, new DateTimeImmutable('2020-01-01')),
            $modifiers->modifiersOf($userId, new DateTimeImmutable('2030-01-01')),
        );
    }

    private static function equip(Uuid $userId, string $itemKey, EquipmentSlot $slot): void
    {
        $repository = self::repository();
        $repository->grant($userId, $itemKey, Uuid::v7(), new DateTimeImmutable());
        $item = self::catalog()->find($itemKey);
        self::assertNotNull($item);
        $repository->equip($userId, $item, $slot);
    }

    private static function itemModifiers(): ItemModifiers
    {
        return new ItemModifiers(self::repository(), self::catalog());
    }

    private static function repository(): InventoryItemRepository
    {
        $repository = self::getContainer()->get(InventoryItemRepository::class);
        self::assertInstanceOf(InventoryItemRepository::class, $repository);

        return $repository;
    }

    private static function catalog(): ItemCatalog
    {
        $catalog = self::getContainer()->get(ItemCatalog::class);
        self::assertInstanceOf(ItemCatalog::class, $catalog);

        return $catalog;
    }
}
