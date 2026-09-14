<?php

declare(strict_types=1);

namespace App\Tests\Rewards\Domain;

use App\Rewards\Domain\CraftingRecipes;
use App\Rewards\Domain\ItemCatalog;
use App\Shared\Application\FrozenGameRulesets;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CraftingRecipesTest extends TestCase
{
    public function testOnlyActiveRecipesAreOffered(): void
    {
        $rows = [['key' => 'BOOTS', 'result_item' => 'BOOTS', 'quantity' => 2, 'costs' => ['DUST' => 10], 'active' => false]];
        self::assertSame([], new CraftingRecipes($rows, $this->items())->all());
        $rows[0]['active'] = true;
        $recipe = new CraftingRecipes($rows, $this->items())->find('BOOTS');
        self::assertNotNull($recipe);
        self::assertSame(['DUST' => 10], $recipe->costs);
        self::assertSame(2, $recipe->quantity);
    }

    /** @param array<string, mixed> $change */
    #[DataProvider('invalidRecipes')]
    public function testRefusesInvalidCostsAndResults(array $change): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CraftingRecipes([array_replace(['key' => 'BOOTS', 'result_item' => 'BOOTS', 'quantity' => 1, 'costs' => ['DUST' => 10]], $change)], $this->items());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidRecipes(): iterable
    {
        yield 'empty' => [['costs' => []]];
        yield 'zero' => [['costs' => ['DUST' => 0]]];
        yield 'negative' => [['costs' => ['DUST' => -1]]];
        yield 'decimal' => [['costs' => ['DUST' => 1.5]]];
        yield 'equipment cost' => [['costs' => ['BOOTS' => 1]]];
        yield 'missing resource' => [['costs' => ['UNKNOWN' => 1]]];
        yield 'resource result' => [['result_item' => 'DUST']];
        yield 'zero result' => [['quantity' => 0]];
    }

    public function testActiveRecipeCannotReferenceAnInactiveResource(): void
    {
        $snapshot = ['items' => [['key' => 'DUST', 'active' => false, 'rarity' => 'COMMON', 'kind' => 'RESOURCE', 'price_coins' => 0, 'modifiers' => []], ['key' => 'BOOTS', 'rarity' => 'COMMON', 'slot' => 'FEET', 'price_coins' => 0, 'modifiers' => []]]];
        $this->expectException(InvalidArgumentException::class);
        new CraftingRecipes([['key' => 'BOOTS', 'result_item' => 'BOOTS', 'quantity' => 1, 'costs' => ['DUST' => 10]]], ItemCatalog::runtime(new FrozenGameRulesets($snapshot, 'test')));
    }

    public function testResourceHasNeitherSlotNorModifiers(): void
    {
        self::assertNull($this->items()->find('DUST')?->slot);
        self::assertSame([], $this->items()->find('DUST')?->modifiers);
        $this->expectException(InvalidArgumentException::class);
        new ItemCatalog([['key' => 'DUST', 'rarity' => 'COMMON', 'kind' => 'RESOURCE', 'slot' => 'FEET', 'price_coins' => 0, 'modifiers' => []]]);
    }

    private function items(): ItemCatalog
    {
        return new ItemCatalog([['key' => 'DUST', 'rarity' => 'COMMON', 'kind' => 'RESOURCE', 'price_coins' => 0, 'modifiers' => []], ['key' => 'BOOTS', 'rarity' => 'COMMON', 'slot' => 'FEET', 'price_coins' => 0, 'modifiers' => []]]);
    }
}
