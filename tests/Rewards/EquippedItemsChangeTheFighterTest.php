<?php

declare(strict_types=1);

namespace App\Tests\Rewards;

use App\Rewards\Application\EquipItem;
use App\Rewards\Application\EquipItemHandler;
use App\Rewards\Domain\ItemCatalog;
use App\Rewards\Infrastructure\Doctrine\InventoryItemRepository;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * **Le test qui compte**, dans les mots du ticket #29 : équiper, puis `POST /api/battles`, et
 * le `Fighter` du joueur dans la réponse a changé. Le moteur (#224) était déjà prouvé sur des
 * modificateurs programmés ; celui-ci le prouve sur un objet réellement équipé, du YAML
 * jusqu'à la réponse HTTP.
 *
 * Traverse `Rewards` et `Combat` volontairement : Deptrac ne s'applique qu'à `src/`, et ce qui
 * se prouve ici est justement que les deux ne se sont jamais parlés directement — tout passe
 * par le port `ModifierResolver` de `Shared`, rien à câbler.
 */
final class EquippedItemsChangeTheFighterTest extends ApiTestCase
{
    /**
     * `IRON_GAUNTLETS` porte `{ type: STRENGTH_BONUS, value: 350 }` (catalogue DB publié) : sur un
     * compte neuf (Strength à zéro), la dérivation vaut
     * `base_damage + intdiv(350 * damage_per_1000_strength, 1000)` — voir le docblock de
     * `FighterFactory`. Le calcul exact n'est pas recopié ici : c'est le baseline, tiré du
     * même endpoint sans l'objet, qui sert de référence.
     */
    public function testEquippingAStrengthItemIncreasesTheFightersDamage(): void
    {
        $baseline = $this->openAccount('baseline@grrind.app');
        $withoutGear = self::decode($this->fight($baseline, 'sans-objet'));

        $geared = $this->openAccount('geared@grrind.app');
        self::equip($geared->id, 'IRON_GAUNTLETS');
        $withGear = self::decode($this->fight($geared, 'avec-objet'));

        $damageWithoutGear = $withoutGear['player'];
        $damageWithGear = $withGear['player'];
        self::assertIsArray($damageWithoutGear);
        self::assertIsArray($damageWithGear);

        self::assertGreaterThan(
            $damageWithoutGear['damage'],
            $damageWithGear['damage'],
            'Un `STRENGTH_BONUS` équipé doit se voir dans les dégâts du combattant.',
        );
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

    private function fight(Account $account, string $key): Response
    {
        return $this->post('/api/battles', [], $account->headers + ['Idempotency-Key' => $key]);
    }
}
