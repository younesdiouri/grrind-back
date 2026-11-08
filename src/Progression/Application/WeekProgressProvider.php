<?php

declare(strict_types=1);

namespace App\Progression\Application;

use App\Progression\Infrastructure\Doctrine\XpTransactionRepository;
use App\Shared\Application\GameRulesets;
use App\Shared\Domain\Alam\AlamCalendar;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * La semaine est **celle des caractéristiques** (`AlamCalendar::week()`, remise à zéro du
 * dimanche soir), pas la semaine civile du streak : c'est elle que les jauges remplissent,
 * et le client ne recode pas le calendrier.
 *
 * La semaine d'avant se lit par la même fenêtre, décalée : son début est la veille de la
 * nôtre. Passer par `week()` plutôt que retrancher sept jours garde l'heure locale de la
 * remise à zéro au changement d'heure.
 */
final readonly class WeekProgressProvider
{
    public function __construct(
        private XpTransactionRepository $ledger,
        private GameRulesets $rules,
        private ClockInterface $clock,
    ) {
    }

    public function of(Uuid $userId): WeekProgress
    {
        $calendar = AlamCalendar::runtime($this->rules);
        $week = $calendar->week($this->clock->now());
        $before = $calendar->week($week['start']->modify('-1 second'));
        $previous = $this->ledger->totalsBetween($userId, $before['start'], $before['reset']);

        return new WeekProgress(
            $week['start'],
            $week['reset'],
            $this->ledger->totalsBetween($userId, $week['start'], $week['reset']),
            // Zéro gain ne donne aucune cible : une jauge « pleine » à zéro ne dirait rien.
            $previous->xp > 0 ? $previous : null,
            $this->ledger->writtenBetween($userId, $week['start'], $week['reset']),
        );
    }
}
