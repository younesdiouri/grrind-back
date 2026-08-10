<?php

declare(strict_types=1);

namespace App\Training\UI\Http\Response;

use App\Training\Application\SessionPage;

/**
 * Une page d'historique telle que le client la lit : la liste, et de quoi demander la
 * suite.
 *
 * L'enveloppe n'est pas décorative. Rendre un tableau nu à la racine interdirait
 * d'ajouter quoi que ce soit plus tard sans casser le décodage du client — et il y aura
 * un « plus tard », ne serait-ce que pour les totaux d'un écran de statistiques.
 *
 * `nextCursor` à `null` signifie « il n'y a plus rien après » : le client s'arrête là,
 * sans avoir eu besoin d'un total.
 */
final readonly class SessionPageResource
{
    /**
     * @param list<TrainingSessionResource> $sessions
     */
    public function __construct(
        public array $sessions,
        public ?string $nextCursor,
    ) {
    }

    public static function from(SessionPage $page): self
    {
        return new self(
            array_map(TrainingSessionResource::from(...), $page->sessions),
            $page->nextCursor?->toRfc4122(),
        );
    }

    /**
     * @return array{sessions: list<array<string, string|int|null>>, nextCursor: string|null}
     */
    public function toArray(): array
    {
        return [
            'sessions' => array_map(static fn (TrainingSessionResource $s): array => $s->toArray(), $this->sessions),
            'nextCursor' => $this->nextCursor,
        ];
    }
}
