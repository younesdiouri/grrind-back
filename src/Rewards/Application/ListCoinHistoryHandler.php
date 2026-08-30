<?php

declare(strict_types=1);

namespace App\Rewards\Application;

use App\Rewards\Domain\CoinTransaction;
use App\Rewards\Infrastructure\Doctrine\CoinTransactionRepository;
use App\Shared\UI\Http\Cursor;

/**
 * Découpe le ledger de pièces en pages — même découpe qu'{@see \App\Progression\Application\ListXpHistoryHandler}
 * et qu'{@see \App\Combat\Application\ListBattlesHandler} : une ligne de plus que demandé est
 * lue à chaque appel, et si elle existe, il y a une suite.
 *
 * Même curseur que les deux autres historiques — {@see Cursor} — et pour la même raison :
 * `(occurredAt, id)` est le couple que `CoinTransactionRepository::history()` compare pour
 * départager deux écritures de la même date, voir son docblock.
 */
final readonly class ListCoinHistoryHandler
{
    public function __construct(private CoinTransactionRepository $ledger)
    {
    }

    public function __invoke(ListCoinHistory $query): CoinHistoryPage
    {
        $found = $this->ledger->history($query, $query->limit + 1);

        if (\count($found) <= $query->limit) {
            return new CoinHistoryPage($found, null);
        }

        // Non vide par construction : plus de lignes que la limite, elle-même >= 1.
        /** @var non-empty-list<CoinTransaction> $page */
        $page = \array_slice($found, 0, $query->limit);
        $last = $page[array_key_last($page)];

        return new CoinHistoryPage($page, Cursor::of($last->occurredAt(), $last->id()));
    }
}
