<?php

declare(strict_types=1);

namespace App\Progression\UI\Http\Response;

use App\Progression\Application\TitleBoard;
use App\Progression\Domain\TitleProgress;
use App\Progression\Infrastructure\Translation\TitleTranslator;
use App\Shared\Application\PlayerTitle;

/**
 * Le mur des titres tel que le client le reçoit : le catalogue entier, traduit et situé,
 * plus l'identifiant de celui qui est porté.
 *
 * **Tous les titres, pas seulement les acquis.** Un mur qui ne montrerait que l'atteint ne
 * donnerait rien à viser, et le client ne pourrait plus proposer d'écran de sélection.
 *
 * `activeTitleId` est redondant avec le tableau — il évite au client de le parcourir pour
 * savoir quoi cocher, et il dit `null` sans ambiguïté quand rien n'est porté.
 */
final readonly class TitleBoardResource
{
    /**
     * @param list<PlayerTitle> $titles
     */
    private function __construct(
        public ?string $activeTitleId,
        public array $titles,
    ) {
    }

    public static function from(TitleBoard $board, TitleTranslator $translator): self
    {
        return new self(
            // Lu du mur et non du champ brut : un titre retiré du catalogue laisse sa ligne
            // en base, et il n'a rien à faire dans une réponse qui ne le liste plus.
            $board->active()?->title->id,
            array_map(
                static fn (TitleProgress $progress): PlayerTitle => $translator->describe(
                    $progress,
                    $board->unlockedAtOf($progress->title->id),
                ),
                $board->titles,
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'activeTitleId' => $this->activeTitleId,
            'titles' => array_map(static fn (PlayerTitle $title): array => $title->toArray(), $this->titles),
        ];
    }
}
