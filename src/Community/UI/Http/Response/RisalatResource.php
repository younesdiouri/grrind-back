<?php

declare(strict_types=1);

namespace App\Community\UI\Http\Response;

use App\Community\Application\RisalatBoard;
use App\Community\Application\RisalaView;

/**
 * L'écran des Risālāt d'un bloc : les vivantes, puis le tour en cours.
 *
 * Les deux ensemble parce que l'onglet ne s'ouvre jamais sans les deux — même raison que
 * `GuildDetail` au #117. Et dans cet ordre, qui est celui de l'écran : ce qui court
 * maintenant d'abord, ce qui se prépare ensuite.
 */
final readonly class RisalatResource
{
    public function __construct(
        /** @var list<RisalaResource> */
        public array $risalat,
        public ?RisalaTurnResource $turn,
    ) {
    }

    public static function from(RisalatBoard $board): self
    {
        return new self(
            array_map(static fn (RisalaView $risala): RisalaResource => RisalaResource::from($risala), $board->live),
            null === $board->turn ? null : RisalaTurnResource::from($board->turn),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'risalat' => array_map(static fn (RisalaResource $risala): array => $risala->toArray(), $this->risalat),
            'turn' => $this->turn?->toArray(),
        ];
    }
}
