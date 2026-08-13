<?php

declare(strict_types=1);

namespace App\Progression\Application;

use App\Progression\Domain\XpTransaction;
use App\Progression\Infrastructure\Doctrine\XpTransactionRepository;
use App\Shared\UI\Http\Cursor;

/**
 * Découpe le ledger en pages. Une ligne de plus que demandé est lue à chaque appel : si
 * elle existe, il y a une suite, et le curseur suivant est la dernière écriture **rendue**.
 * Un curseur désigne une position dans les données et non un rang — la page ne glisse donc
 * pas quand une séance se crédite pendant le défilement.
 *
 * Même découpe que {@see \App\Training\Application\ListWorkoutsHandler}, et c'est voulu :
 * deux paginations qui se ressemblent à moitié coûteraient deux implémentations côté client.
 */
final readonly class ListXpHistoryHandler
{
    public function __construct(private XpTransactionRepository $ledger)
    {
    }

    public function __invoke(ListXpHistory $query): XpHistoryPage
    {
        $found = $this->ledger->history($query, $query->limit + 1);

        if (\count($found) <= $query->limit) {
            return new XpHistoryPage($found, null);
        }

        // Non vide par construction : plus de lignes que la limite, elle-même >= 1.
        /** @var non-empty-list<XpTransaction> $page */
        $page = \array_slice($found, 0, $query->limit);
        $last = $page[array_key_last($page)];

        return new XpHistoryPage($page, Cursor::of($last->occurredAt(), $last->id()));
    }
}
