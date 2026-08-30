<?php

declare(strict_types=1);

namespace App\Tests\Rewards\Domain;

use App\Rewards\Domain\EquipmentSlot;
use App\Rewards\Domain\ItemCatalog;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Modifier\ModifierType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Le moteur de jeu se teste sans aucune infra — même geste que `CombatRulesTest` : ce
 * catalogue ne touche ni au conteneur ni à la base.
 */
final class ItemCatalogTest extends TestCase
{
    public function testFindsAKnownItemByKey(): void
    {
        $catalog = new ItemCatalog([
            ['key' => 'STARTER_BOOTS', 'rarity' => 'COMMON', 'slot' => 'FEET', 'price_coins' => 10, 'modifiers' => [
                ['type' => 'XP_MULTIPLIER', 'value' => 5, 'discipline' => 'RUNNING'],
            ]],
        ]);

        $item = $catalog->find('STARTER_BOOTS');

        self::assertNotNull($item);
        self::assertSame(EquipmentSlot::Feet, $item->slot);
        self::assertSame(10, $item->priceCoins);
        self::assertCount(1, $item->modifiers);
        self::assertSame(ModifierType::XpMultiplier, $item->modifiers[0]->type);
        self::assertSame(5, $item->modifiers[0]->value);
        self::assertSame(Discipline::Running, $item->modifiers[0]->discipline);
    }

    public function testAModifierWithoutADisciplineIsGlobal(): void
    {
        $catalog = new ItemCatalog([
            ['key' => 'CLOAK', 'rarity' => 'UNCOMMON', 'slot' => 'CHEST', 'price_coins' => 20, 'modifiers' => [
                ['type' => 'LOOT_LUCK', 'value' => 10],
            ]],
        ]);

        self::assertNull($catalog->find('CLOAK')?->modifiers[0]->discipline);
    }

    public function testFindRendsNullPourUneCleAbsente(): void
    {
        $catalog = new ItemCatalog([
            ['key' => 'CLOAK', 'rarity' => 'UNCOMMON', 'slot' => 'CHEST', 'price_coins' => 20, 'modifiers' => []],
        ]);

        self::assertNull($catalog->find('INEXISTANT'));
    }

    public function testAllRendLeCatalogueDansLOrdreDeDeclaration(): void
    {
        $catalog = new ItemCatalog([
            ['key' => 'FIRST', 'rarity' => 'COMMON', 'slot' => 'FEET', 'price_coins' => 1, 'modifiers' => []],
            ['key' => 'SECOND', 'rarity' => 'COMMON', 'slot' => 'HANDS', 'price_coins' => 1, 'modifiers' => []],
        ]);

        self::assertSame(['FIRST', 'SECOND'], array_map(static fn ($item) => $item->key, $catalog->all()));
    }

    public function testRefuseUnCatalogueVide(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ItemCatalog([]);
    }

    public function testRefuseUneCleDupliquee(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ItemCatalog([
            ['key' => 'CLOAK', 'rarity' => 'COMMON', 'slot' => 'CHEST', 'price_coins' => 1, 'modifiers' => []],
            ['key' => 'CLOAK', 'rarity' => 'RARE', 'slot' => 'CHEST', 'price_coins' => 1, 'modifiers' => []],
        ]);
    }

    public function testRefuseUneRareteInconnue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ItemCatalog([
            ['key' => 'CLOAK', 'rarity' => 'MYTHIC', 'slot' => 'CHEST', 'price_coins' => 1, 'modifiers' => []],
        ]);
    }

    public function testRefuseUnEmplacementInconnu(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ItemCatalog([
            ['key' => 'CLOAK', 'rarity' => 'COMMON', 'slot' => 'TAIL', 'price_coins' => 1, 'modifiers' => []],
        ]);
    }

    /**
     * `STRENGTH_BONUS` a servi ici jusqu'au #224 : c'est exactement le type que ce ticket
     * ouvre, la preuve qu'il ne l'était pas encore ne tient plus. `BOGUS_TYPE` reprend le
     * même rôle — n'importe quelle chaîne que `ModifierType::tryFrom()` ne connaît pas.
     */
    public function testRefuseUnTypeDeModificateurInconnu(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ItemCatalog([
            ['key' => 'CLOAK', 'rarity' => 'COMMON', 'slot' => 'CHEST', 'price_coins' => 1, 'modifiers' => [
                ['type' => 'BOGUS_TYPE', 'value' => 500],
            ]],
        ]);
    }

    public function testRefuseUneDisciplineInconnueSurUnModificateur(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ItemCatalog([
            ['key' => 'CLOAK', 'rarity' => 'COMMON', 'slot' => 'CHEST', 'price_coins' => 1, 'modifiers' => [
                ['type' => 'XP_MULTIPLIER', 'value' => 5, 'discipline' => 'CURLING'],
            ]],
        ]);
    }

    /**
     * Le refus ajouté au #29 : `FighterFactory` traite les neuf types de combat comme
     * globaux, une discipline sur l'un d'eux mentirait sur ce que le moteur fait réellement —
     * voir le docblock de la classe. `STRENGTH_BONUS` porte le refus, n'importe lequel des
     * neuf ferait l'affaire.
     */
    public function testRefuseUneDisciplineSurUnModificateurDeCombat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IRON_GAUNTLETS');

        new ItemCatalog([
            ['key' => 'IRON_GAUNTLETS', 'rarity' => 'COMMON', 'slot' => 'HANDS', 'price_coins' => 1, 'modifiers' => [
                ['type' => 'STRENGTH_BONUS', 'value' => 350, 'discipline' => 'RUNNING'],
            ]],
        ]);
    }

    /**
     * Le pendant positif : les neuf types de combat restent parfaitement valides tant
     * qu'aucune discipline ne les accompagne — c'est déjà ce que livre `items.yaml`.
     */
    public function testAccepteUnModificateurDeCombatSansDiscipline(): void
    {
        $catalog = new ItemCatalog([
            ['key' => 'IRON_GAUNTLETS', 'rarity' => 'COMMON', 'slot' => 'HANDS', 'price_coins' => 1, 'modifiers' => [
                ['type' => 'STRENGTH_BONUS', 'value' => 350],
            ]],
        ]);

        self::assertNull($catalog->find('IRON_GAUNTLETS')?->modifiers[0]->discipline);
    }
}
