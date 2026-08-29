<?php

declare(strict_types=1);

namespace App\Combat\Domain;

/**
 * Une ligne de la timeline d'un combat — le contrat d'animation, exactement comme
 * `RewardSummary` l'est pour une séance. Fermé à cinq formes, chacune sa classe plutôt qu'un
 * type générique à discriminant : {@see BattleStarted} ouvre, {@see Attack}, {@see Dodge} et
 * {@see ExtraTurn} rythment les tours, {@see BattleFinished} referme.
 *
 * **L'ordre dans {@see BattleOutcome::$timeline} est l'ordre de l'animation**, et trois
 * règles ne s'y négocient pas :
 *
 * - `ExtraTurn` s'émet **après** l'attaque — portée ou esquivée — qui l'a déclenché et
 *   **avant** celle qu'il accorde — le client anime la cause puis l'effet, jamais un tour
 *   bonus surgi de nulle part ;
 * - chaque `Attack` porte les PV restants de sa cible, jamais un delta : le client ne
 *   soustrait rien, il pose la barre là où le combat en est ;
 * - `Dodge` remplace l'`Attack` que le tour aurait produit, il ne s'ajoute jamais à côté :
 *   un tour porte l'un ou l'autre, jamais les deux (#218).
 */
interface BattleEvent
{
}
