<?php

declare(strict_types=1);

namespace App\Combat\Application;

use App\Combat\Domain\Battle;
use App\Combat\Infrastructure\Doctrine\BattleRepository;
use App\Shared\UI\Http\Cursor;

/**
 * Découpe l'historique en pages — le pendant exact de
 * {@see \App\Training\Application\ListWorkoutsHandler}. Une ligne de plus que demandé est lue
 * à chaque appel : si elle existe, il y a une suite, et le curseur suivant est le dernier
 * élément **rendu**, pas le premier de la suite.
 */
final readonly class ListBattlesHandler
{
    public function __construct(private BattleRepository $battles)
    {
    }

    public function __invoke(ListBattles $query): BattlePage
    {
        $found = $this->battles->history($query, $query->limit + 1);

        if (\count($found) <= $query->limit) {
            return new BattlePage($found, null);
        }

        // Non vide par construction : plus de lignes que la limite, elle-même >= 1.
        /** @var non-empty-list<Battle> $page */
        $page = \array_slice($found, 0, $query->limit);
        $last = $page[array_key_last($page)];

        return new BattlePage($page, Cursor::of($last->foughtAt(), $last->id()));
    }
}
