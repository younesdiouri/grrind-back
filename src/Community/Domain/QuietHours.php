<?php

declare(strict_types=1);

namespace App\Community\Domain;

use App\Shared\Application\GameRulesets;
use App\Shared\Domain\RuntimeRuleset;
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
final class QuietHours
{
    use RuntimeRuleset;

    public function __construct(
        private int $startHour,
        private int $endHour,
        ?GameRulesets $rulesets = null,
    ) {
        $this->useRuntimeRulesets($rulesets);
        if ($startHour < 0 || $startHour > 23 || $endHour < 0 || $endHour > 23) {
            throw new InvalidArgumentException('Les heures calmes se bornent entre 0 et 23.');
        }
    }

    public static function runtime(GameRulesets $rulesets): self
    {
        return self::fromSnapshot($rulesets->snapshot(), $rulesets);
    }

    /**
     * Le premier instant à partir duquel une notification peut repartir — `$instant`
     * lui-même si la plage n'est pas ouverte.
     *
     * **Pour ce qui se reporte, pas pour ce qui se perd (#194).** Une annonce d'activité de
     * guilde qui tombe en pleine nuit est simplement abandonnée : elle sera périmée au
     * réveil, et le co-équipier la lira dans l'app. Un tour de Risāla, lui, a une échéance —
     * le manquer coûte une semaine à toute la guilde — et 20h à Paris tombe à 3h du matin à
     * Tokyo *toutes les semaines*. Abandonner condamnerait un joueur lointain au silence
     * permanent ; réveiller à 3h serait pire ; reporter est le seul des trois qui tienne.
     *
     * La sortie de plage se calcule par le calendrier du fuseau et non par une addition de
     * secondes : un changement d'heure ne doit ni avancer ni retarder le réveil d'une heure.
     */
    public function endsAfter(DateTimeImmutable $instant, Timezone $timezone): DateTimeImmutable
    {
        if ($this->isRuntimeRuleset()) {
            return $this->runtimeValue()->endsAfter($instant, $timezone);
        }

        if (!$this->contains($instant, $timezone)) {
            return $instant;
        }

        $local = $instant->setTimezone($timezone->toDateTimeZone());
        $end = $local->setTime($this->endHour, 0);

        // La borne de fin est déjà passée dans la journée locale : c'est une plage qui
        // franchit minuit, et elle se referme demain matin.
        return $end > $local ? $end : $end->modify('+1 day')->setTime($this->endHour, 0);
    }

    public function contains(DateTimeImmutable $instant, Timezone $timezone): bool
    {
        if ($this->isRuntimeRuleset()) {
            return $this->runtimeValue()->contains($instant, $timezone);
        }

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

    /** @param array<string, mixed> $snapshot */
    private static function fromSnapshot(array $snapshot, ?GameRulesets $rulesets = null): self
    {
        /** @var array{quiet_hours_start_hour: int, quiet_hours_end_hour: int} $notifications */
        $notifications = $snapshot['notifications'];

        return new self($notifications['quiet_hours_start_hour'], $notifications['quiet_hours_end_hour'], $rulesets);
    }
}
