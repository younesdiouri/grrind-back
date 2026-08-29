<?php

declare(strict_types=1);

namespace App\Combat\UI\Http\Response;

use App\Combat\Domain\Enemy;
use App\Combat\Domain\EnemyCatalog;
use App\Combat\Infrastructure\Translation\EnemyTranslator;

/**
 * Le catalogue entier, tel que `GET /api/enemies` le rend — l'enveloppe qui manquait.
 *
 * **Niché sous `enemies`, jamais un tableau nu à la racine.** C'est la convention du
 * produit, sans exception ailleurs — {@see \App\Training\UI\Http\Response\WorkoutPageResource}
 * rend `{"workouts": [...], "nextCursor": ...}`,
 * {@see \App\Progression\UI\Http\Response\TitleBoardResource} rend `{"titles": [...]}` — et
 * c'est ce qui laisse la place à un champ frère plus tard (un compteur, une pagination le
 * jour où le catalogue grossit au-delà du niveau 50, voir `combat.yaml`) sans jamais casser
 * le décodage d'un client déjà déployé.
 *
 * Ennemis ordinaires puis boss, dans l'ordre de déclaration de chaque liste — même ordre que
 * {@see EnemiesController} composait avant que cette classe existe.
 */
final readonly class EnemyCatalogResource
{
    /**
     * @param list<EnemyResource> $enemies
     */
    private function __construct(
        public array $enemies,
    ) {
    }

    public static function from(EnemyCatalog $catalog, EnemyTranslator $translator): self
    {
        return new self(
            array_map(
                static fn (Enemy $enemy): EnemyResource => EnemyResource::from($enemy, $translator),
                [...$catalog->all(), ...$catalog->bosses()],
            ),
        );
    }

    /**
     * @return array{enemies: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'enemies' => array_map(static fn (EnemyResource $enemy): array => $enemy->toArray(), $this->enemies),
        ];
    }
}
