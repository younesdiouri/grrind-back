<?php

declare(strict_types=1);

namespace App\Training\Domain;

use App\Shared\Application\GameRulesets;
use App\Shared\Domain\RuntimeRuleset;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Ce qui entre à l'import, et ce que ça vaut au maximum.
 *
 * Ce ne sont plus les règles d'un agrégat mais celles d'un **arbitrage** : un workout
 * importé est un fait, et refuser un fait n'a pas de sens en soi. Ce qu'on refuse, c'est de
 * le **créditer** — et chaque refus porte un nom, remonté au joueur.
 *
 * Trois seuils, trois façons différentes de dire non :
 *
 * - **le plancher** écarte ce qui n'est pas une séance : douze secondes, c'est un faux
 *   départ sur la montre ;
 * - **le plafond** n'écarte rien, il **écrête** — l'enregistrement oublié sur la montre
 *   rend quatre heures créditées au lieu de tout perdre ;
 * - **la fenêtre** est le garde-fou du virage santé, celui qui empêche trois ans d'Apple
 *   Health de vider le produit de son intérêt en une requête. Au-delà, le workout est écrit
 *   mais ne rapporte rien.
 *
 * Le cooldown, lui, est parti pour de bon : Apple produit trois workouts d'affilée sans
 * demander la permission à personne, et refuser le troisième reviendrait à refuser un fait
 * qui a eu lieu.
 *
 * Valeurs et justification de chaque seuil : `config/game/v1/training.yaml`.
 */
final class WorkoutRules
{
    use RuntimeRuleset;

    public function __construct(
        public int $minimumDurationSeconds,
        public int $maximumDurationSeconds,
        public int $importWindowDays,
        ?GameRulesets $rulesets = null,
    ) {
        $this->useRuntimeRulesets($rulesets);
        // Une configuration incohérente tombe au démarrage, pas le jour où un joueur
        // croise le cas.
        if ($minimumDurationSeconds < 0) {
            throw new InvalidArgumentException('Un seuil de durée ne peut pas être négatif.');
        }

        if ($maximumDurationSeconds < $minimumDurationSeconds) {
            throw new InvalidArgumentException('Le plafond de durée est sous le plancher : aucun workout ne pourrait être retenu.');
        }

        // Une fenêtre nulle ne créditerait plus rien : la séance du matin est déjà passée
        // quand elle arrive.
        if ($importWindowDays < 1) {
            throw new InvalidArgumentException('La fenêtre d\'import doit couvrir au moins une journée.');
        }
    }

    public static function runtime(GameRulesets $rulesets): self
    {
        return self::fromSnapshot($rulesets->snapshot(), $rulesets);
    }

    public function maximumDurationSeconds(): int
    {
        return $this->isRuntimeRuleset() ? $this->runtimeValue()->maximumDurationSeconds() : $this->maximumDurationSeconds;
    }

    public function importWindowDays(): int
    {
        return $this->isRuntimeRuleset() ? $this->runtimeValue()->importWindowDays() : $this->importWindowDays;
    }

    /** Sous le plancher, ce n'est pas une séance courte : c'est un faux départ. */
    public function isTooShort(int $durationSeconds): bool
    {
        if ($this->isRuntimeRuleset()) {
            return $this->runtimeValue()->isTooShort($durationSeconds);
        }

        return $durationSeconds < $this->minimumDurationSeconds;
    }

    /**
     * La durée **retenue** : celle qui sert au calcul d'XP, écrêtée au plafond. Le workout,
     * lui, garde la durée réellement mesurée — on ne réécrit pas ce que la montre a vu, on
     * décide seulement de ce qu'on en paie.
     */
    public function retainedDuration(int $durationSeconds): int
    {
        if ($this->isRuntimeRuleset()) {
            return $this->runtimeValue()->retainedDuration($durationSeconds);
        }

        return min($durationSeconds, $this->maximumDurationSeconds);
    }

    /**
     * Un workout hors fenêtre entre en base sans être crédité.
     *
     * La borne porte sur `endedAt` et non sur `startedAt` : c'est la fin qui dit quand
     * l'effort a cessé, et une randonnée de deux jours n'a pas à se faire refuser pour son
     * départ.
     */
    public function isWithinWindow(DateTimeImmutable $endedAt, DateTimeImmutable $now): bool
    {
        if ($this->isRuntimeRuleset()) {
            return $this->runtimeValue()->isWithinWindow($endedAt, $now);
        }

        return $endedAt >= $now->modify(\sprintf('-%d days', $this->importWindowDays));
    }

    /** @param array<string, mixed> $snapshot */
    private static function fromSnapshot(array $snapshot, ?GameRulesets $rulesets = null): self
    {
        /** @var array{minimum_duration_seconds: int, maximum_duration_seconds: int, import_window_days: int} $training */
        $training = $snapshot['training'];

        return new self($training['minimum_duration_seconds'], $training['maximum_duration_seconds'], $training['import_window_days'], $rulesets);
    }
}
