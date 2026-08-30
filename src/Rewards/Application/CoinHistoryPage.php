<?php

declare(strict_types=1);

namespace App\Rewards\Application;

use App\Rewards\Domain\CoinTransaction;
use App\Shared\UI\Http\Cursor;

/**
 * Une tranche de ledger et de quoi demander la suivante — le pendant exact d'{@see \App\Progression\Application\XpHistoryPage}
 * et de {@see \App\Combat\Application\BattlePage}. Pas de solde ici : c'est une propriété du
 * compte, pas de la page, {@see \App\Rewards\UI\Http\Response\CoinHistoryPageResource} la lit
 * séparément par {@see CoinLedger::balanceOf()}.
 *
 * Pas de total non plus, même raison que ses deux pendants : un défilement infini n'en a
 * aucun usage, le client s'arrête quand `nextCursor` est `null`.
 */
final readonly class CoinHistoryPage
{
    /**
     * @param list<CoinTransaction> $transactions
     */
    public function __construct(
        public array $transactions,
        public ?Cursor $nextCursor,
    ) {
    }
}
