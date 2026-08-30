<?php

declare(strict_types=1);

namespace App\Shared\Application;

use App\Shared\Domain\Event\WorkoutImported;

/**
 * Tirer le loot d'un workout importé, et dire ce qui est tombé.
 *
 * **Le troisième port annoncé par le docblock de {@see SessionRewards}** : « le loot et le
 * streak ajouteront chacun le leur, parce que chacun rend une matière différente et
 * s'insère à une place précise de la séquence ». Ce n'est pas une extension de
 * `SessionRewards` — un contrat unique agrégeant XP et loot devrait porter un vocabulaire
 * commun à `Progression` et `Rewards`, qui n'en partagent aucun, exactement le
 * raisonnement que `SessionRewards` tient déjà pour refuser de s'agréger avec le streak.
 *
 * **Appelé après {@see SessionRewards::creditFor()}, jamais avant.** L'ordre de
 * `ARCHITECTURE.md` place le loot après l'XP et les titres : un tirage ne doit pas décider
 * du niveau auquel une table s'ouvre avant que la séance qui vient d'être créditée n'ait
 * fini de faire monter ce niveau. `$reward` est le compte rendu de ce crédit, pas un
 * second DTO à maintenir en synchronisation.
 *
 * **Seule une séance créditée tire, et cette classe ne repose pas la question.**
 * `LedgerSessionRewards` a déjà répondu — via `XpRates::credits()` — au moment de produire
 * `$reward` : `$reward->reason` porte le verdict pour une discipline qui ne crédite pas
 * d'XP par conception (la marche, #167). L'implémentation lit ce champ plutôt que
 * d'interroger une seconde fois une règle qui appartient à `Progression` et que Deptrac lui
 * interdirait de toute façon de lire directement.
 *
 * **Appelé N fois dans un lot, jamais une par synchronisation.** Chaque workout crédité
 * tire sa propre graine — `random_bytes(32)`, jamais une graine partagée entre plusieurs
 * séances du même import — parce que chaque tirage est un événement de jeu distinct, audité
 * pour lui-même.
 *
 * **Dans la transaction d'import, comme `SessionRewards`.** Une panne en aval doit défaire
 * le tirage, l'objet crédité à l'inventaire et la ligne de pièces avec le reste : un objet
 * en inventaire sans sa ligne d'audit, ou une pièce créditée deux fois par un rejeu, sont
 * des pertes silencieuses.
 *
 * @see SessionDrop pour ce qui en sort
 */
interface SessionDrops
{
    /**
     * `$reward` est le `SessionReward` que {@see SessionRewards::creditFor()} vient de
     * rendre pour **ce même** workout — pas un autre appel, pas une relecture : c'est de
     * lui que vient le niveau qui ouvre les tables (`$reward->level`, celui d'après ce
     * crédit) et le verdict « créditée ou non » (`$reward->reason`).
     *
     * Rend un {@see SessionDrop} vide — aucun objet, `coins` à zéro gain mais un solde
     * réel — pour une séance non créditée ou pour laquelle aucune table n'est éligible :
     * jamais une absence, pour que le client anime la même séquence dans tous les cas.
     */
    public function rollFor(WorkoutImported $workout, SessionReward $reward): SessionDrop;
}
