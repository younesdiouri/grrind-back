<?php

declare(strict_types=1);

namespace App\Rewards\Application;

use App\Rewards\Domain\CraftingAudit;
use App\Rewards\Domain\CraftingRecipe;
use App\Rewards\Domain\CraftingRecipes;
use App\Rewards\Domain\Exception\RecipeUnavailable;
use App\Rewards\Domain\ItemCatalog;
use App\Rewards\Domain\ItemModifier;
use App\Rewards\Infrastructure\Doctrine\InventoryItemRepository;
use App\Rewards\Infrastructure\Translation\ItemTranslator;
use App\Shared\Application\DroppedItem;
use App\Shared\Application\DroppedItemModifier;
use App\Shared\Application\GameRulesets;
use App\Shared\Domain\Idempotency\Exception\IdempotencyKeyReused;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * La fabrication tient le verrou inventaire pendant consommation, production et audit.
 * Le snapshot ouvert pour la requête ne change pas après une publication concurrente.
 * L’audit conserve aussi la clé et le reçu : une panne après commit mais avant réponse
 * HTTP ne peut pas consommer une seconde fois après expiration de la réservation HTTP.
 */
final readonly class Crafting
{
    public function __construct(private GameRulesets $rulesets, private InventoryItemRepository $inventory, private ItemTranslator $translator, private EntityManagerInterface $manager, private ClockInterface $clock)
    {
    }

    /** @return array<string, mixed> */
    public function recipes(Uuid $userId): array
    {
        $catalog = ItemCatalog::runtime($this->rulesets);
        $recipes = $this->catalog($catalog);
        $owned = [];
        foreach ($this->inventory->ownedByPlayer($userId) as $line) {
            $owned[$line->itemKey()] = $line->quantity();
        }

        return ['recipes' => array_map(function (CraftingRecipe $recipe) use ($catalog, $owned): array {
            $costs = $this->costs($recipe, $catalog, $owned);

            return ['key' => $recipe->key, 'result' => ['item' => $this->describe($catalog, $recipe->resultKey), 'quantity' => $recipe->quantity], 'costs' => $costs, 'canCraft' => array_all($recipe->costs, static fn (int $quantity, string $key): bool => ($owned[$key] ?? 0) >= $quantity)];
        }, $recipes->all()), 'rulesetVersion' => $this->rulesets->version()];
    }

    /** @return array<string, mixed> */
    public function craft(Uuid $userId, string $key, ?string $idempotencyKey = null): array
    {
        return $this->inventory->transactional(function () use ($userId, $key, $idempotencyKey): array {
            $requestKey = null;
            if (null !== $idempotencyKey) {
                $requestKey = hash('sha256', $userId->toRfc4122().':craft:'.$idempotencyKey);
                $this->manager->getConnection()->executeStatement('SELECT pg_advisory_xact_lock(hashtext(:key))', ['key' => $requestKey]);
                $previous = $this->manager->getRepository(CraftingAudit::class)->findOneBy(['requestKey' => $requestKey]);
                if (null !== $previous) {
                    if ($previous->recipeKey() !== $key) {
                        throw new IdempotencyKeyReused($idempotencyKey);
                    }

                    return $previous->receipt();
                }
            }
            $catalog = ItemCatalog::runtime($this->rulesets);
            $recipe = $this->catalog($catalog)->find($key) ?? throw new RecipeUnavailable($key);
            $version = $this->rulesets->version();
            $now = $this->clock->now();
            $remaining = [];
            foreach ($recipe->costs as $resourceKey => $quantity) {
                $remaining[$resourceKey] = $this->inventory->consumeQuantity($userId, $resourceKey, $quantity)->quantity();
            }
            $result = $this->inventory->grantQuantity($userId, $recipe->resultKey, $recipe->quantity, $now);
            $audit = new CraftingAudit($userId, $recipe->key, $recipe->resultKey, $recipe->quantity, $recipe->costs, $version, $now, $requestKey);
            $receipt = ['id' => $audit->id()->toRfc4122(), 'recipeKey' => $recipe->key, 'result' => ['item' => $this->describe($catalog, $recipe->resultKey), 'quantity' => $recipe->quantity, 'ownedQuantity' => $result->quantity()], 'costs' => $this->costs($recipe, $catalog, $remaining), 'rulesetVersion' => $version, 'craftedAt' => $now->format(DateTimeInterface::ATOM)];
            $audit->rememberReceipt($receipt);
            $this->manager->persist($audit);

            return $receipt;
        });
    }

    private function catalog(ItemCatalog $catalog): CraftingRecipes
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->rulesets->snapshot()['recipes'] ?? [];

        return new CraftingRecipes($rows, $catalog);
    }

    /**
     * @param array<string, int> $owned
     *
     * @return list<array<string, mixed>>
     */
    private function costs(CraftingRecipe $recipe, ItemCatalog $catalog, array $owned): array
    {
        $costs = [];
        foreach ($recipe->costs as $key => $quantity) {
            $costs[] = ['item' => $this->describe($catalog, $key), 'quantity' => $quantity, 'ownedQuantity' => $owned[$key] ?? 0];
        }

        return $costs;
    }

    /** @return array<string, mixed> */
    private function describe(ItemCatalog $catalog, string $key): array
    {
        $item = $catalog->find($key) ?? throw new LogicException('Objet de recette absent.');

        return new DroppedItem($item->key, $item->kind->value, $this->translator->nameOf($item->key), $item->rarity->value, $item->slot?->value, array_map(static fn (ItemModifier $modifier): DroppedItemModifier => new DroppedItemModifier($modifier->type->value, $modifier->value, $modifier->discipline?->value), $item->modifiers), $item->priceCoins, $this->translator->imageUrlOf($item->key))->toArray();
    }
}
