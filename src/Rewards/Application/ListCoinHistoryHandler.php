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
 * **Le curseur suivant porte `occurredAt`, alors que le tri ne le regarde pas.** `Cursor`
 * est la forme partagée par les trois historiques — voir son docblock — et rester dessus
 * plutôt que d'en écrire une seconde vaut de porter un champ inutilisé ici : il reste
 * opaque, le client ne le lit jamais, seul `CoinTransactionRepository::history()` décode
 * `$cursor->id`, voir son docblock pour pourquoi c'est bien lui qui décide de l'ordre.
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
