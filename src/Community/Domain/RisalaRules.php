<?php

declare(strict_types=1);

namespace App\Community\Domain;

use App\Shared\Application\GameRulesets;
use App\Shared\Domain\RuntimeRuleset;
use App\Shared\Domain\Timezone;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Le calendrier des Risālāt et leur barème : quand la semaine bascule, combien de temps une
 * Risāla vit, et ce qu'elle rapporte de chaque côté.
 *
 * De l'équilibrage, pas des constantes de classe — même geste que {@see GuildRules} : la
 * bonne durée d'un défi est une question de produit qui bougera après les premiers joueurs.
 *
 * ## Un instant unique pour tout le monde
 *
 * `weekTimezone` n'est **pas** le fuseau d'un joueur. C'est celui de la semaine de jeu, et
 * il est le même pour toutes les guildes : la révélation est simultanée ou elle n'est pas.
 * Un joueur à Tokyo découvre sa Risāla le lundi matin, et c'est le prix accepté pour que
 * « tout le monde apprend la même chose au même moment » reste vrai.
 *
 * C'est le seul endroit du projet où une date de jeu ne se calcule pas dans le fuseau du
 * joueur, et l'exception est assumée : le streak et les plafonds quotidiens mesurent *sa*
 * journée à lui, la Risāla est un rendez-vous collectif.
 *
 * ## L'échéance et la révélation sont le même instant
 *
 * Le membre tiré a jusqu'à `deadline` pour choisir ; c'est à `deadline` que sa Risāla part.
 * Un seul instant sur la grille hebdomadaire, donc, et pas deux : c'est ce qui garantit
 * qu'il n'existe jamais de fenêtre où le choix est encore possible alors que la révélation
 * a déjà eu lieu — ni l'inverse.
 */
final class RisalaRules
{
    use RuntimeRuleset;

    public Timezone $weekTimezone;

    /**
     * @param string $weekTimezone identifiant IANA — la validation est celle de {@see Timezone},
     *                             pas une seconde formulation qui finirait par diverger
     */
    public function __construct(
        public int $activeWeeks,
        /** ISO-8601 : 1 = lundi, 7 = dimanche. */
        public int $revealWeekday,
        public int $revealHour,
        string $weekTimezone,
        /** Ce que touche un membre sur la discipline demandée, en pourcentage du socle. */
        public int $recipientBonusPercent,
        /** Ce que touche celui qui l'a envoyée, sur la même discipline. */
        public int $senderBonusPercent,
        ?GameRulesets $rulesets = null,
    ) {
        $this->useRuntimeRulesets($rulesets);
        $this->weekTimezone = Timezone::fromString($weekTimezone);

        // Une Risāla d'une seule semaine expirerait à l'instant même où la suivante est
        // révélée : le roulement disparaîtrait, et avec lui les quinze jours pour caler la
        // séance — c'est-à-dire tout ce que la mécanique achète.
        if ($activeWeeks < 2) {
            throw new InvalidArgumentException(\sprintf('Une Risāla doit vivre au moins deux semaines pour qu\'il y en ait deux à la fois, %d demandée(s).', $activeWeeks));
        }

        if ($revealWeekday < 1 || $revealWeekday > 7) {
            throw new InvalidArgumentException(\sprintf('Le jour de révélation est un jour ISO-8601 entre 1 et 7, %d donné.', $revealWeekday));
        }

        if ($revealHour < 0 || $revealHour > 23) {
            throw new InvalidArgumentException(\sprintf('L\'heure de révélation se borne entre 0 et 23, %d donnée.', $revealHour));
        }

        // Un bonus nul ferait une Risāla qui ne change rien à la séance : la mécanique
        // n'existerait plus, mais tout continuerait de tourner — le pire des deux mondes,
        // puisque personne ne verrait la panne.
        if ($recipientBonusPercent < 1 || $senderBonusPercent < 1) {
            throw new InvalidArgumentException(\sprintf('Une Risāla doit rapporter quelque chose aux deux côtés, %d %% et %d %% demandés.', $recipientBonusPercent, $senderBonusPercent));
        }

        // L'expéditeur au moins aussi bien servi que ceux qu'il défie retournerait la
        // mécanique : il choisirait le sport qu'il pratique déjà, et la Risāla cesserait de
        // faire découvrir quoi que ce soit. Le déséquilibre *est* la règle.
        if ($senderBonusPercent >= $recipientBonusPercent) {
            throw new InvalidArgumentException(\sprintf('L\'expéditeur ne peut pas gagner autant que ceux qu\'il défie : %d %% contre %d %%.', $senderBonusPercent, $recipientBonusPercent));
        }
    }

    public static function runtime(GameRulesets $rulesets): self
    {
        return self::fromSnapshot($rulesets->snapshot(), $rulesets);
    }

    public function recipientBonusPercent(): int
    {
        return $this->isRuntimeRuleset() ? $this->runtimeValue()->recipientBonusPercent() : $this->recipientBonusPercent;
    }

    public function senderBonusPercent(): int
    {
        return $this->isRuntimeRuleset() ? $this->runtimeValue()->senderBonusPercent() : $this->senderBonusPercent;
    }

    /**
     * Le prochain rendez-vous hebdomadaire, **strictement après** `$instant`.
     *
     * Strictement, et c'est ce qui fait tourner la roue : la bascule s'exécute *à* l'instant
     * du rendez-vous, et demande depuis là quand est le suivant. Une comparaison large
     * rendrait le rendez-vous courant, donc une échéance déjà échue, donc un tour scellé
     * `MISSED` dans l'heure qui suit sa naissance.
     *
     * Le calcul passe par le calendrier du fuseau et non par une addition de secondes : un
     * changement d'heure ne doit ni avancer ni retarder la révélation d'une heure.
     */
    public function nextRevealAfter(DateTimeImmutable $instant): DateTimeImmutable
    {
        if ($this->isRuntimeRuleset()) {
            return $this->runtimeValue()->nextRevealAfter($instant);
        }

        $local = $instant->setTimezone($this->weekTimezone->toDateTimeZone());

        $candidate = $local->setTime($this->revealHour, 0);
        $candidate = $candidate->modify(\sprintf('+%d days', ($this->revealWeekday - (int) $candidate->format('N') + 7) % 7));

        // `modify()` conserve l'heure locale au passage d'un changement d'heure, mais la
        // reposer coûte une ligne et ferme la question pour de bon.
        $candidate = $candidate->setTime($this->revealHour, 0);

        return $candidate > $local ? $candidate : $candidate->modify('+7 days')->setTime($this->revealHour, 0);
    }

    /**
     * Quand s'éteint une Risāla révélée à `$revealedAt` — un instant de la grille, donc son
     * expiration en est un aussi.
     *
     * C'est ce qui fait qu'il y en a exactement deux : à la révélation de la semaine N, celle
     * de N−2 expire à la seconde même où celle de N naît. Un décalage d'une seule seconde
     * entre les deux ferait apparaître une troisième Risāla vivante, ou un trou.
     */
    public function expiryOf(DateTimeImmutable $revealedAt): DateTimeImmutable
    {
        if ($this->isRuntimeRuleset()) {
            return $this->runtimeValue()->expiryOf($revealedAt);
        }

        return $revealedAt->modify(\sprintf('+%d weeks', $this->activeWeeks));
    }

    /** @param array<string, mixed> $snapshot */
    private static function fromSnapshot(array $snapshot, ?GameRulesets $rulesets = null): self
    {
        /** @var array{risala: array{active_weeks: int, reveal_day: int, reveal_hour: int, week_timezone: string, recipient_bonus_percent: int, sender_bonus_percent: int}} $community */
        $community = $snapshot['community'];
        $risala = $community['risala'];

        return new self($risala['active_weeks'], $risala['reveal_day'], $risala['reveal_hour'], $risala['week_timezone'], $risala['recipient_bonus_percent'], $risala['sender_bonus_percent'], $rulesets);
    }
}
