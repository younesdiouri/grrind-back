<?php

declare(strict_types=1);

namespace App\Community\Domain;

use App\Shared\Domain\Timezone;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * La plage, dans la journée du **destinataire**, où une notification de guilde ne part
 * pas — jamais celle de l'auteur ni du serveur, voir `notifications.yaml` (#133).
 *
 * Une plage qui franchit minuit (22h → 8h, le cas d'une nuit normale) n'est pas un
 * intervalle simple : {@see contains()} encode l'union des deux bornes plutôt que de
 * laisser l'appelant s'en souvenir.
 */
final readonly class QuietHours
{
    public function __construct(
        private int $startHour,
        private int $endHour,
    ) {
        if ($startHour < 0 || $startHour > 23 || $endHour < 0 || $endHour > 23) {
            throw new InvalidArgumentException('Les heures calmes se bornent entre 0 et 23.');
        }
    }

    public function contains(DateTimeImmutable $instant, Timezone $timezone): bool
    {
        // Identiques : aucune plage calme n'est configurée, plutôt qu'une plage de
        // vingt-quatre heures — un réglage à zéro ne doit jamais rendre la guilde muette.
        if ($this->startHour === $this->endHour) {
            return false;
        }

        $hour = (int) $instant->setTimezone($timezone->toDateTimeZone())->format('G');

        if ($this->startHour < $this->endHour) {
            return $hour >= $this->startHour && $hour < $this->endHour;
        }

        return $hour >= $this->startHour || $hour < $this->endHour;
    }
}
