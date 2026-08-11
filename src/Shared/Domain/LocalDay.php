<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Une journée telle que le joueur la vit : de minuit à minuit **dans son fuseau**, exprimée
 * en instants UTC pour interroger des colonnes stockées en UTC.
 *
 * Sans elle, un joueur à Tokyo verrait son plafond quotidien se réinitialiser à 9 h du
 * matin et sa série se rompre en plein après-midi. C'est l'invariant de CLAUDE.md rendu
 * calculable ; le streak (#24) s'en servira à l'identique.
 *
 * **Une journée ne dure pas toujours 24 heures.** Au passage à l'heure d'été elle en fait
 * 23, à l'heure d'hiver 25. D'où la borne de fin calculée en ajoutant *un jour civil dans
 * le fuseau* puis en revenant à minuit, et non en ajoutant 86 400 secondes : cette seconde
 * méthode déborde ou tronque deux fois par an, et personne ne s'en aperçoit avant qu'un
 * joueur perde une série.
 *
 * Préoccupation de calendrier, sans logique de jeu, adossée à `Timezone` : elle est dans
 * `Shared` à ce titre.
 */
final readonly class LocalDay
{
    private function __construct(
        /** Le jour civil vécu par le joueur, `AAAA-MM-JJ`. C'est la clé d'un streak. */
        public string $date,
        /** Minuit local, en UTC. Borne **incluse**. */
        public DateTimeImmutable $startsAt,
        /** Le minuit local suivant, en UTC. Borne **exclue**. */
        public DateTimeImmutable $endsAt,
    ) {
    }

    public static function containing(DateTimeImmutable $instant, Timezone $timezone): self
    {
        $local = $instant->setTimezone($timezone->toDateTimeZone());

        $startsAt = $local->setTime(0, 0);
        // `modify('+1 day')` raisonne en jours civils dans le fuseau courant ; le
        // `setTime` qui suit rattrape les fuseaux dont le décalage change à minuit même.
        $endsAt = $startsAt->modify('+1 day')->setTime(0, 0);

        return new self(
            $local->format('Y-m-d'),
            $startsAt->setTimezone(new DateTimeZone('UTC')),
            $endsAt->setTimezone(new DateTimeZone('UTC')),
        );
    }

    /** Longueur réelle de la journée. 23 ou 25 heures deux fois par an. */
    public function lengthInSeconds(): int
    {
        return $this->endsAt->getTimestamp() - $this->startsAt->getTimestamp();
    }

    public function contains(DateTimeImmutable $instant): bool
    {
        return $instant >= $this->startsAt && $instant < $this->endsAt;
    }
}
