<?php

declare(strict_types=1);

namespace App\Combat\Application;

use App\Combat\Domain\Battle;
use App\Shared\UI\Http\Cursor;

/**
 * Une tranche d'historique et de quoi demander la suivante — le pendant exact de
 * {@see \App\Training\Application\WorkoutPage}. Pas de total, même raison : un défilement
 * infini n'en a aucun usage, et le client s'arrête quand `nextCursor` est `null`.
 */
final readonly class BattlePage
{
    /**
     * @param list<Battle> $battles
     */
    public function __construct(
        public array $battles,
        public ?Cursor $nextCursor,
    ) {
    }
}
