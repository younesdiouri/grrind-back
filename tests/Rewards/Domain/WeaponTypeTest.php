<?php

declare(strict_types=1);

namespace App\Tests\Rewards\Domain;

use App\Rewards\Domain\ItemCatalog;
use App\Rewards\Domain\WeaponType;
use App\Shared\Infrastructure\Config\GameRulesetVersion;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WeaponTypeTest extends TestCase
{
    public function testHistoricalWeaponsDefaultToFistsWhileOtherItemsHaveNoWeaponType(): void
    {
        foreach (['GOLDEN_BOXING_GLOVES', 'LE_POIDS_LOURD', 'HALTER_EGO'] as $key) {
            $catalog = new ItemCatalog([['key' => $key, 'rarity' => 'COMMON', 'slot' => 'WEAPON', 'price_coins' => 20, 'modifiers' => []]]);
            self::assertSame(WeaponType::Fist, $catalog->find($key)?->weaponType);
        }
        $catalog = new ItemCatalog([['key' => 'SHIRT', 'rarity' => 'COMMON', 'slot' => 'CHEST', 'price_coins' => 10, 'modifiers' => []]]);
        self::assertNull($catalog->find('SHIRT')?->weaponType);
    }

    public function testAnimationFamilyDoesNotChangeModifiersOrGameplayVersion(): void
    {
        $entry = ['key' => 'FER_PLAY', 'rarity' => 'COMMON', 'slot' => 'WEAPON', 'price_coins' => 25, 'modifiers' => [['type' => 'DAMAGE_BONUS', 'value' => 2]]];
        $before = new ItemCatalog([$entry]);
        $after = new ItemCatalog([$entry + ['weapon_type' => 'SWORD']]);
        $sword = $after->find('FER_PLAY');
        self::assertNotNull($sword);
        self::assertSame(WeaponType::Sword, $sword->weaponType);
        self::assertEquals($before->find('FER_PLAY')?->modifiers, $sword->modifiers);
        self::assertSame(GameRulesetVersion::of(['items' => [$entry]]), GameRulesetVersion::of(['items' => [$entry + ['weapon_type' => 'SWORD']]]));
    }

    #[DataProvider('invalidTypes')]
    public function testInvalidAnimationFamiliesCannotBePublished(string $slot, string $type): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ItemCatalog([['key' => 'INVALID', 'rarity' => 'COMMON', 'slot' => $slot, 'weapon_type' => $type, 'price_coins' => 1, 'modifiers' => []]]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidTypes(): iterable
    {
        yield 'unknown type' => ['WEAPON', 'LASER'];
        yield 'type on armor' => ['CHEST', 'SWORD'];
    }
}
