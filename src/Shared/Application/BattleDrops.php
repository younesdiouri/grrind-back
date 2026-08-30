<?php

declare(strict_types=1);

namespace App\Shared\Application;

use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Tirer le loot d'un combat, et dire ce qui est tombé — le pendant de {@see SessionDrops}
 * pour le combat (#227), pas une extension : `Combat` n'a pas le vocabulaire d'une séance
 * (aucune discipline, aucune durée), et un contrat unique pour les deux origines forcerait
 * l'une des deux à porter des champs qui ne la concernent pas — même raisonnement que
 * {@see SessionDrops} tient déjà pour ne pas s'agréger avec {@see SessionRewards}.
 *
 * **Seule une victoire rapporte, et cette classe ne repose pas la question à côté.**
 * `$victory` porte le verdict — calculé une fois par {@see \App\Combat\Application\FightBattleHandler}
 * depuis `BattleResult::Victory === $outcome->result` — et l'implémentation le lit plutôt
 * que d'en déduire un second de son côté : une défaite, ou une victoire par `max_turns`
 * sans KO, ne tirent rien, exactement comme {@see WorkoutSessionDrops} lit `$reward->reason`
 * sans reposer la question de savoir si une séance crédite. Une récompense de consolation
 * ferait du combat perdu la stratégie optimale, puisqu'il est plus rapide à jouer.
 *
 * **`$victory` traverse en booléen, jamais `BattleResult`.** `Shared` ne dépend d'aucun
 * module métier (voir `deptrac.yaml`) : un type de `Combat\Domain` ne peut pas figurer dans
 * la signature d'un port qui vit ici.
 *
 * **La table de tirage se choisit par `$enemyKey`**, jamais par palier ou par discipline :
 * un boss et l'ennemi ordinaire du même niveau ne donnent pas la même chose, c'est tout
 * l'intérêt d'aller le chercher. Voir {@see \App\Rewards\Domain\LootRoller::rollForAdversary()}.
 *
 * **`$battleId` est l'identifiant de la ligne de combat, pas une clé technique du tirage.**
 * L'implémentation le porte tel quel dans `LootRoll::$causeId`, pour la même raison qu'un
 * drop de séance y porte l'identifiant du workout : sans lui, aucun tirage ne serait
 * rattachable au combat qui l'a produit. Il est fourni **déjà résolu** par l'appelant —
 * `Combat` le génère avant de construire la ligne, voir le docblock de
 * {@see \App\Combat\Domain\Battle} pour pourquoi.
 *
 * **`$foughtAt` sert à la fois de date d'obtention et d'instant de résolution des
 * modificateurs**, exactement l'instant que {@see \App\Combat\Application\FighterFactory}
 * a déjà reçu pour dériver le combattant — un combat n'a aucune antériorité, contrairement
 * à un workout.
 */
interface BattleDrops
{
    public function rollFor(Uuid $playerId, string $enemyKey, bool $victory, Uuid $battleId, DateTimeImmutable $foughtAt): BattleDrop;
}
