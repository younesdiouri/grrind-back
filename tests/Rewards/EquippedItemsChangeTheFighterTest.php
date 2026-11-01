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
    /** Le bonus direct de dégâts traverse l’inventaire jusqu’au combattant HTTP. */
    public function testEquippingADirectDamageItemIncreasesTheFightersDamage(): void
    {
        $baseline = $this->openAccount('baseline@grrind.app');
        $withoutGear = self::decode($this->fight($baseline, 'sans-objet'));

        $geared = $this->openAccount('geared@grrind.app');
        self::equip($geared->id, 'LE_POIDS_LOURD');
        $withGear = self::decode($this->fight($geared, 'avec-objet'));

        $damageWithoutGear = $withoutGear['player'];
        $damageWithGear = $withGear['player'];
        self::assertIsArray($damageWithoutGear);
        self::assertIsArray($damageWithGear);

        self::assertGreaterThan(
            $damageWithoutGear['damage'],
            $damageWithGear['damage'],
            'Un bonus direct de dégâts équipé doit se voir dans le combattant.',
        );
    }

    private static function equip(Uuid $userId, string $itemKey): void
    {
        $repository = self::getContainer()->get(InventoryItemRepository::class);
        self::assertInstanceOf(InventoryItemRepository::class, $repository);

        $catalog = self::getContainer()->get(ItemCatalog::class);
        self::assertInstanceOf(ItemCatalog::class, $catalog);

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
