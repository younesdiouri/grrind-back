<?php

declare(strict_types=1);

namespace App\Rewards\Domain;

use InvalidArgumentException;

/** Le même validateur sert au brouillon et au snapshot figé d'une fabrication. */
final readonly class CraftingRecipes
{
    /** @var array<string, CraftingRecipe> */
    private array $recipes;

    /** @param list<array<string, mixed>> $rows */
    public function __construct(array $rows, ItemCatalog $items)
    {
        $recipes = [];
        $keys = [];
        foreach ($rows as $row) {
            $key = $row['key'] ?? null;
            $resultKey = $row['result_item'] ?? null;
            $quantity = $row['quantity'] ?? null;
            $costs = $row['costs'] ?? null;
            if (!\is_string($key) || !preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $key) || isset($keys[$key]) || !\is_string($resultKey) || !\is_int($quantity) || $quantity < 1 || !\is_array($costs) || [] === $costs) {
                throw new InvalidArgumentException('La recette doit avoir une clé unique, un résultat et des coûts strictement positifs.');
            }
            $keys[$key] = true;
            $active = $row['active'] ?? true;
            if (!\is_bool($active)) {
                throw new InvalidArgumentException('L’activation d’une recette doit être un booléen.');
            }
            $result = $active ? $items->findAvailable($resultKey) : $items->findHistorical($resultKey);
            if (null === $result || ItemKind::Equipment !== $result->kind) {
                throw new InvalidArgumentException('Une recette produit un équipement disponible.');
            }
            $validated = [];
            foreach ($costs as $resourceKey => $amount) {
                if (!\is_string($resourceKey) || !\is_int($amount) || $amount < 1) {
                    throw new InvalidArgumentException('Chaque coût doit être une quantité entière strictement positive.');
                }
                $resource = $active ? $items->findAvailable($resourceKey) : $items->findHistorical($resourceKey);
                if (null === $resource || ItemKind::Resource !== $resource->kind) {
                    throw new InvalidArgumentException('Les coûts d’une recette référencent uniquement des ressources disponibles.');
                }
                $validated[$resourceKey] = $amount;
            }
            if ($active) {
                $recipes[$key] = new CraftingRecipe($key, $resultKey, $quantity, $validated);
            }
        }
        $this->recipes = $recipes;
    }

    public function find(string $key): ?CraftingRecipe
    {
        return $this->recipes[$key] ?? null;
    }

    /** @return list<CraftingRecipe> */
    public function all(): array
    {
        return array_values($this->recipes);
    }
}
