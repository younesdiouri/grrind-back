<?php

declare(strict_types=1);

namespace App\Training\Domain;

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
final readonly class WorkoutRules
{
    public function __construct(
        public int $minimumDurationSeconds,
        public int $maximumDurationSeconds,
        public int $importWindowDays,
    ) {
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

    /** Sous le plancher, ce n'est pas une séance courte : c'est un faux départ. */
    public function isTooShort(int $durationSeconds): bool
    {
        return $durationSeconds < $this->minimumDurationSeconds;
    }

    /**
     * La durée **retenue** : celle qui sert au calcul d'XP, écrêtée au plafond. Le workout,
     * lui, garde la durée réellement mesurée — on ne réécrit pas ce que la montre a vu, on
     * décide seulement de ce qu'on en paie.
     */
    public function retainedDuration(int $durationSeconds): int
    {
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
        return $endedAt >= $now->modify(\sprintf('-%d days', $this->importWindowDays));
    }
}
