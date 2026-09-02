<?php

declare(strict_types=1);

namespace App\Rewards\Domain;

/**
 * Une entrée pondérée d'une {@see LootTable}. `$itemKey` à `null` est le tirage bredouille —
 * un résultat à part entière, pas un trou dans la table, voir le docblock de le snapshot publié.
 *
 * Le poids ne se valide pas ici : une entrée à zéro est une entrée désactivée sans être
 * retirée de la table, et c'est la somme de toute la table qui doit rester positive — voir
 * le docblock de {@see LootTable}, qui est seule à pouvoir le dire.
 */
final readonly class LootEntry
{
    public function __construct(
        public ?string $itemKey,
        public int $weight,
    ) {
    }
}
