<?php

declare(strict_types=1);

namespace App\Combat\UI\Http\Response;

use App\Combat\Application\BattlePage;
use App\Combat\Domain\Battle;
use App\Combat\Infrastructure\Translation\EnemyTranslator;
use App\Shared\Application\ItemImageUrlResolver;

/**
 * L'enveloppe de `GET /api/battles` — le pendant exact de
 * {@see \App\Training\UI\Http\Response\WorkoutPageResource} : nichée sous `battles`, jamais
 * un tableau nu à la racine, pour la même raison qu'un champ frère puisse s'ajouter plus tard
 * sans casser un client déjà déployé.
 *
 * `nextCursor` à `null` signifie « plus rien après » — le client s'arrête là, sans total.
 */
final readonly class BattlePageResource
{
    /**
     * @param list<BattleSummaryResource> $battles
     */
    public function __construct(
        public array $battles,
        public ?string $nextCursor,
    ) {
    }

    public static function from(BattlePage $page, EnemyTranslator $enemyNames, ?ItemImageUrlResolver $items = null): self
    {
        return new self(
            array_map(
                static fn (Battle $battle): BattleSummaryResource => BattleSummaryResource::from($battle, $enemyNames, $items),
                $page->battles,
            ),
            $page->nextCursor?->encoded(),
        );
    }

    /**
     * @return array{battles: list<array<string, mixed>>, nextCursor: string|null}
     */
    public function toArray(): array
    {
        return [
            'battles' => array_map(static fn (BattleSummaryResource $battle): array => $battle->toArray(), $this->battles),
            'nextCursor' => $this->nextCursor,
        ];
    }
}
