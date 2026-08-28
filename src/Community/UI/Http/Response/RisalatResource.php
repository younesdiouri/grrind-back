<?php

declare(strict_types=1);

namespace App\Community\UI\Http\Response;

use App\Community\Application\RisalatBoard;
use App\Community\Application\RisalaView;
use DateTimeInterface;
use DateTimeZone;

/**
 * L'écran des Risālāt d'un bloc : les vivantes, le tour en cours, puis le prochain rendez-vous.
 *
 * Les trois ensemble parce que l'onglet ne s'ouvre jamais sans eux — même raison que
 * `GuildDetail` au #117. Et dans cet ordre, qui est celui de l'écran : ce qui court
 * maintenant d'abord, ce qui se prépare ensuite, puis le rendez-vous qui fait basculer les
 * deux (#202).
 *
 * **`nextRevealAt` est reposé en UTC avant d'être rendu**, comme {@see \App\Shared\Domain\LocalDay}
 * repose ses bornes : c'est la seule date du contrat calculée dans un autre fuseau que celui du
 * stockage, et l'offset la trahirait. `…T20:00:00+02:00` livre en clair l'heure *et* le fuseau
 * de la semaine de jeu — c'est-à-dire la grille que le #202 refuse justement de rendre, pour
 * que le client ne soit pas invité à recalculer le rendez-vous suivant lui-même.
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
            $board->nextRevealAt->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM),
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
