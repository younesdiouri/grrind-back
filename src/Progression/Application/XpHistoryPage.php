<?php

declare(strict_types=1);

namespace App\Progression\Application;

use App\Progression\Domain\XpTransaction;
use Symfony\Component\Uid\Uuid;

/**
 * Une tranche de ledger et de quoi demander la suivante. Pas de total, pour la même raison
 * qu'à {@see \App\Training\Application\SessionPage} : un `COUNT(*)` par page pour une
 * information dont un défilement infini n'a aucun usage. Le client est au bout quand
 * `nextCursor` est `null`.
 */
final readonly class XpHistoryPage
{
    /**
     * @param list<XpTransaction> $transactions
     */
    public function __construct(
        public array $transactions,
        public ?Uuid $nextCursor,
    ) {
    }
}
