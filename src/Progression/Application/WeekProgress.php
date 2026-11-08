<?php

declare(strict_types=1);

namespace App\Progression\Application;

use App\Progression\Domain\WeekTotals;
use App\Progression\Domain\XpTransaction;
use DateTimeImmutable;

/**
 * La semaine de jeu du joueur (#348) : ses bornes, ce qu'elle a rapporté, et la référence
 * de la semaine d'avant. `previous` est `null` quand il n'y a rien à battre — le client
 * n'invente pas de cible, et le serveur ne plafonne rien.
 */
final readonly class WeekProgress
{
    /**
     * @param list<XpTransaction> $entries
     */
    public function __construct(
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $resetsAt,
        public WeekTotals $current,
        public ?WeekTotals $previous,
        public array $entries,
    ) {
    }
}
