<?php

declare(strict_types=1);

namespace App\Shared\Application;

use App\Shared\Domain\Event\TrainingSessionCompleted;

/**
 * Créditer une séance close, et dire ce qu'elle a rapporté.
 *
 * **Pourquoi un port, alors que la règle n°0 dit d'en écrire le moins possible.** La
 * transaction de complétion appartient à `Training` — c'est lui qui possède la séance et
 * son changement d'état — mais l'XP, la courbe et les titres appartiennent à
 * `Progression`. Deptrac interdit la flèche, et c'est heureux : c'est celle qui ferait de
 * `Training` le module qui sait tout.
 *
 * L'événement de domaine, l'autre chemin autorisé, ne convient pas : il est consommé
 * **après** le COMMIT, alors que la réponse HTTP doit porter le `RewardSummary` et que le
 * crédit doit être annulé si la suite de la transaction échoue. Même raison que pour
 * {@see ModifierContributor}, et pour {@see PlayerTimezones} avant lui.
 *
 * **Un seul consommateur, une seule implémentation.** Le port n'est pas en éventail comme
 * `ModifierContributor` : le loot (Lot 6) et le streak (Lot 5) ajouteront chacun le leur,
 * parce que chacun rend une matière différente et s'insère à une place précise de la
 * séquence. Un contrat unique qui les agrégerait devrait porter un vocabulaire commun à
 * trois modules qui n'en partagent aucun.
 *
 * @see SessionReward pour ce qui en sort
 */
interface SessionRewards
{
    /**
     * **Appelé dans la transaction de complétion, jamais en dehors.** L'implémentation pose
     * un verrou sur la ligne de progression du joueur ; hors transaction, il se relâcherait
     * aussitôt et ne sérialiserait rien.
     *
     * L'argument est l'événement de complétion lui-même, et non un DTO jumeau : il porte
     * déjà exactement ce qu'il faut — qui, quelle discipline, quelle durée **retenue** — et
     * une seconde forme des mêmes champs finirait par en diverger d'un.
     *
     * Une panne se propage plutôt que de se rattraper : une séance close sans XP créditée
     * est une perte silencieuse pour le joueur, et c'est le rôle de la transaction
     * appelante de tout défaire.
     */
    public function creditFor(TrainingSessionCompleted $completion): SessionReward;
}
