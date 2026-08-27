<?php

declare(strict_types=1);

namespace App\Shared\Application;

use App\Shared\Domain\Event\WorkoutImported;

/**
 * Créditer un workout importé, et dire ce qu'il a rapporté.
 *
 * **Pourquoi un port, alors que la règle n°0 dit d'en écrire le moins possible.** La
 * transaction d'import appartient à `Training` — c'est lui qui possède le workout et son
 * écriture — mais l'XP, la courbe et les titres appartiennent à `Progression`. Deptrac
 * interdit la flèche, et c'est heureux : c'est celle qui ferait de `Training` le module qui
 * sait tout.
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
     * **Appelé dans la transaction d'import, jamais en dehors.** L'implémentation pose un
     * verrou sur la ligne de progression du joueur ; hors transaction, il se relâcherait
     * aussitôt et ne sérialiserait rien. Sauf pour une discipline qui ne crédite pas d'XP
     * par conception (#167, la marche) : rien n'y étant écrit, aucun verrou n'y est pris —
     * voir le docblock de `LedgerSessionRewards`.
     *
     * Appelé **N fois** dans un lot, et c'est sans conséquence sur le verrou : un verrou de
     * ligne est ré-entrant dans une transaction, donc il est pris au premier workout et
     * tenu jusqu'au COMMIT. Deux synchronisations concurrentes du même compte s'attendent
     * une fois, pas dix.
     *
     * L'argument est l'événement lui-même, et non un DTO jumeau : il porte déjà exactement
     * ce qu'il faut — qui, quelle discipline, quelle durée **retenue**, et surtout **quand**
     * — et une seconde forme des mêmes champs finirait par en diverger d'un.
     *
     * Une panne se propage plutôt que de se rattraper : un workout écrit sans XP créditée
     * est une perte silencieuse pour le joueur, et c'est le rôle de la transaction appelante
     * de tout défaire. Une discipline qui ne crédite jamais rien n'est pas ce cas : `null`
     * n'y est jamais perdu, c'est le comportement voulu, rendu lisible par `SessionReward::$reason`.
     */
    public function creditFor(WorkoutImported $workout): SessionReward;
}
