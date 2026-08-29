<?php

declare(strict_types=1);

namespace App\Combat\Domain;

/**
 * Une ligne de la timeline d'un combat — le contrat d'animation, exactement comme
 * `RewardSummary` l'est pour une séance. Fermé à quatre formes, chacune sa classe plutôt
 * qu'un type générique à discriminant : {@see BattleStarted} ouvre, {@see Attack} et
 * {@see ExtraTurn} rythment les tours, {@see BattleFinished} referme.
 *
 * **L'ordre dans {@see BattleOutcome::$timeline} est l'ordre de l'animation**, et deux
 * règles ne s'y négocient pas :
 *
 * - `ExtraTurn` s'émet **après** l'attaque qui l'a déclenché et **avant** celle qu'il
 *   accorde — le client anime la cause puis l'effet, jamais un tour bonus surgi de nulle
 *   part ;
 * - chaque `Attack` porte les PV restants de sa cible, jamais un delta : le client ne
 *   soustrait rien, il pose la barre là où le combat en est.
 */
interface BattleEvent
{
}
