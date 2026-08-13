<?php

declare(strict_types=1);

namespace App\Training\UI\Http\Response;

use App\Training\Application\WorkoutPage;

/**
 * L'enveloppe n'est pas décorative : un tableau nu à la racine interdirait d'ajouter quoi
 * que ce soit plus tard sans casser le décodage du client.
 *
 * `nextCursor` à `null` signifie « plus rien après » — le client s'arrête là, sans total.
 */
final readonly class WorkoutPageResource
{
    /**
     * @param list<WorkoutResource> $workouts
     */
    public function __construct(
        public array $workouts,
        public ?string $nextCursor,
    ) {
    }

    public static function from(WorkoutPage $page): self
    {
        return new self(
            array_map(WorkoutResource::from(...), $page->workouts),
            $page->nextCursor?->encoded(),
        );
    }

    /**
     * @return array{workouts: list<array<string, string|int|null>>, nextCursor: string|null}
     */
    public function toArray(): array
    {
        return [
            'workouts' => array_map(static fn (WorkoutResource $workout): array => $workout->toArray(), $this->workouts),
            'nextCursor' => $this->nextCursor,
        ];
    }
}
