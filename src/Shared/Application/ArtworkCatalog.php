<?php

declare(strict_types=1);

namespace App\Shared\Application;

/** Présentation pure : indépendante des statistiques, du loot et du rulesetVersion. */
interface ArtworkCatalog
{
    /** @return array{appearances: list<array<string, mixed>>, equipment: list<array<string, mixed>>} */
    public function all(): array;

    public function selectable(string $appearance): bool;

    public function equipmentVersion(string $itemKey): ?string;
}
