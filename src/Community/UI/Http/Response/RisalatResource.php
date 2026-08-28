<?php

declare(strict_types=1);

namespace App\Community\UI\Http\Response;

use App\Community\Application\RisalatBoard;
use App\Community\Application\RisalaView;
use DateTimeInterface;

/**
 * L'écran des Risālāt d'un bloc : les vivantes, le tour en cours, puis le prochain rendez-vous.
 *
 * Les trois ensemble parce que l'onglet ne s'ouvre jamais sans eux — même raison que
 * `GuildDetail` au #117. Et dans cet ordre, qui est celui de l'écran : ce qui court
 * maintenant d'abord, ce qui se prépare ensuite, puis le rendez-vous qui fait basculer les
 * deux (#202).
 */
final readonly class RisalatResource
{
    public function __construct(
        /** @var list<RisalaResource> */
        public array $risalat,
        public ?RisalaTurnResource $turn,
        public string $nextRevealAt,
    ) {
    }

    public static function from(RisalatBoard $board): self
    {
        return new self(
            array_map(static fn (RisalaView $risala): RisalaResource => RisalaResource::from($risala), $board->live),
            null === $board->turn ? null : RisalaTurnResource::from($board->turn),
            $board->nextRevealAt->format(DateTimeInterface::ATOM),
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
            'nextRevealAt' => $this->nextRevealAt,
        ];
    }
}
