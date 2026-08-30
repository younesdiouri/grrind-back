<?php

declare(strict_types=1);

namespace App\Combat\Application;

use App\Combat\Domain\CombatRules;
use App\Combat\Domain\Enemy;
use App\Combat\Domain\Fighter;
use App\Shared\Application\ModifierResolver;
use App\Shared\Application\PlayerProgression;
use App\Shared\Domain\Modifier\Modifier;
use App\Shared\Domain\Modifier\ModifierType;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * La traduction caractéristique → combattant, tranchée au #210 (`Mobility` au #218) :
 *
 * | Effet en combat            | Caractéristique | Pourquoi celle-là                                                |
 * |-----------------------------|------------------|--------------------------------------------------------------------|
 * | Points de vie               | `Vitality`       | `attributes.yaml` : « ce sera les HP du personnage au PvP ».      |
 * | Dégâts infligés              | `Strength`       | Direct.                                                            |
 * | Réduction des dégâts reçus   | `Endurance`      | La capacité à encaisser, sans doublonner les HP de Vitality.      |
 * | Tour supplémentaire          | `Dexterity`      | La caractéristique du réflexe dans `attributes.yaml`.              |
 * | Chance d'esquive             | `Mobility`       | Strength frappe, Endurance encaisse, Dexterity rejoue, Mobility    |
 * |                              |                  | évite — les quatre caractéristiques lisibles en combat (#218).    |
 *
 * ## Socle + contribution, jamais un multiplicateur du socle
 *
 * Chaque effet suit `base + caractéristique × coefficient / 1000`, jamais `base × facteur` :
 * un compte neuf (les quatre caractéristiques à zéro, `Vitality::of()` rendant 0 par
 * construction — voir son docblock) reçoit donc le socle nu, pas zéro. Il se bat, et il perd
 * s'il est mauvais : le socle n'est pas une commodité de code, c'est ce qui empêche la page
 * blanche d'être une exclusion plutôt qu'une punition.
 *
 * ## Les plafonds s'appliquent ici, pas au simulateur
 *
 * `mitigation_cap_permille`, `extra_turn_cap_permille` et `dodge_cap_permille` sont appliqués
 * à la dérivation : un `Fighter` sort déjà borné. {@see \App\Combat\Domain\BattleSimulator}
 * ne replafonne rien et n'a pas à le faire — voir le docblock de {@see Fighter}, qui ne
 * garantit lui-même que la non-négativité, pas les plafonds de `CombatRules`.
 *
 * ## Le vocabulaire des modificateurs s'ouvre au combat (#224)
 *
 * `Combat` devient le cinquième consommateur de {@see ModifierResolver} — voir le docblock
 * de {@see ModifierType} pour pourquoi ni un port `EquippedStats` ni un mécanisme parallèle
 * n'était la bonne réponse. **L'ordre est le contrat**, et il est testé :
 *
 * 1. un bonus de caractéristique pure (`*_BONUS` sur les quatre caractéristiques) s'ajoute
 *    au total lu du snapshot, **avant** la dérivation par les coefficients de `CombatRules` ;
 * 2. la dérivation elle-même, inchangée ;
 * 3. un bonus de stat directe (`HP_BONUS`, `DAMAGE_BONUS`, `MITIGATION_BONUS`,
 *    `EXTRA_TURN_BONUS`, `DODGE_BONUS`) s'ajoute au résultat de la dérivation ;
 * 4. **puis seulement** les trois plafonds de `CombatRules`. Un objet ne franchit jamais un
 *    plafond — à 1000 ‰ un combattant devient invulnérable ou ne rend jamais la main, et la
 *    boucle ne se termine plus sur ses propres mérites (voir `CombatRules`).
 *
 * **Composition de plusieurs modificateurs du même type : la somme.** Deux objets « +200
 * Strength », ou un objet et une future compétence sur le même type, s'additionnent — même
 * choix et même raison qu'{@see \App\Progression\Domain\XpCalculator} pour `XP_MULTIPLIER` :
 * chaque contribution reste vraie isolément, et la composition ne dépend pas de l'ordre dans
 * lequel le resolver les a rendus. {@see sumOf()} porte ce choix.
 *
 * **La portée par discipline de `Modifier` ne s'applique pas ici, et c'est délibéré.** Un
 * combat n'a lieu « dans » aucune discipline — contrairement à une séance de sport, il n'y a
 * pas de sport en cours dont un modificateur scopé pourrait dépendre. `sumOf()` somme donc
 * sur le seul {@see ModifierType}, sans regarder {@see Modifier::$discipline} : un objet qui
 * porterait par erreur une discipline sur un de ces neuf types serait traité comme global,
 * jamais comme silencieusement ignoré.
 *
 * **Le plancher de `Fighter` se pose ici, pas dans son constructeur.** Un bonus négatif —
 * une malédiction, plus tard — ne doit pas pouvoir produire un combattant sous les planchers
 * de {@see Fighter} : `max(1, …)` pour les points de vie, `max(0, …)` pour le reste, avant
 * la construction. Compter sur l'exception de `Fighter` reviendrait à laisser une malédiction
 * assez forte faire échouer un combat au lieu de simplement l'affaiblir.
 *
 * **`forEnemy()` ne lit aucun modificateur.** Un ennemi du catalogue n'a ni équipement ni
 * compétences — voir plus bas.
 *
 * **`$occurredAt` est l'instant de la requête, jamais celui d'un sport.** Contrairement à
 * `ModifierContributor::modifiersOf()` appelé pour un workout, un combat *a lieu* à
 * l'instant où il est joué — même raison que {@see \App\Combat\Domain\Battle::$foughtAt}
 * (voir son docblock). C'est {@see FightBattleHandler}, seul
 * détenteur de l'horloge sur ce chemin, qui la passe ici.
 *
 * ## Aucun port supplémentaire : `PlayerProgression` et `ModifierResolver` entrent déjà par `Shared`
 *
 * `App\Shared\Application\PlayerProgressions` rend, en batch et indexé par UUID, exactement ce
 * dont cette factory a besoin — le niveau (pas utilisé ici), l'`AttributeGains` des quatre
 * caractéristiques et la `vitality` bonifiée. Écrire un huitième port irait contre la règle n°0 :
 * cette classe **reçoit** une `PlayerProgression` déjà résolue, elle ne va rien chercher — pas
 * de repository, pas de base, pas de dépendance à `Progression`. `ModifierResolver` est le même
 * geste : un service de `Shared` déjà branché ailleurs (`GrantXpHandler`), pas un nouveau port.
 *
 * ## Arithmétique entière, sans exception
 *
 * Comme partout sur une valeur de jeu : {@see scale()} reproduit
 * {@see \App\Shared\Domain\Activity\Vitality::scale()}, une division entière tronquée, jamais un
 * flottant. Chaque caractéristique est bornée à zéro avant d'entrer dans le calcul — même geste
 * que {@see \App\Shared\Domain\Activity\Vitality::coefficientPermille()} — parce qu'un total
 * négatif n'a de sens que le temps d'une annulation partielle du ledger, et le laisser passer
 * pousserait un socle sous zéro sans qu'aucun combat ne l'ait mérité.
 */
final readonly class FighterFactory
{
    private const int PERMILLE = 1000;

    public function __construct(
        private CombatRules $rules,
        private ModifierResolver $modifiers,
    ) {
    }

    /**
     * @param Uuid              $playerId   pour interroger {@see ModifierResolver} — `PlayerProgression` ne le porte pas
     * @param DateTimeImmutable $occurredAt l'instant du combat, jamais celui d'un sport — voir le docblock de la classe
     */
    public function forPlayer(PlayerProgression $progression, Uuid $playerId, DateTimeImmutable $occurredAt): Fighter
    {
        $modifiers = $this->modifiers->of($playerId, $occurredAt);
        $attributes = $progression->attributes;

        // Étape 1 du contrat d'ordre : le bonus de caractéristique s'ajoute au total lu du
        // snapshot avant toute dérivation — voir le docblock de la classe.
        $strength = max(0, $attributes->strength + self::sumOf($modifiers, ModifierType::StrengthBonus));
        $endurance = max(0, $attributes->endurance + self::sumOf($modifiers, ModifierType::EnduranceBonus));
        $mobility = max(0, $attributes->mobility + self::sumOf($modifiers, ModifierType::MobilityBonus));
        $dexterity = max(0, $attributes->dexterity + self::sumOf($modifiers, ModifierType::DexterityBonus));
        // Jamais bonifiée par un modificateur — voir le docblock de `ModifierType`.
        $vitality = max(0, $progression->vitality);

        return new Fighter(
            // Étapes 2 à 4 : la dérivation, puis le bonus de stat directe, puis — pour les
            // trois stats plafonnées — le plafond de `CombatRules`. Le `max(0, …)` (`max(1, …)`
            // pour les PV) pose le plancher de `Fighter` ici plutôt que de compter sur son
            // exception : un bonus négatif affaiblit un combattant, il ne fait pas échouer le
            // combat.
            hp: max(1, $this->rules->baseHp + self::scale($vitality, $this->rules->hpPer1000Vitality) + self::sumOf($modifiers, ModifierType::HpBonus)),
            damage: max(0, $this->rules->baseDamage + self::scale($strength, $this->rules->damagePer1000Strength) + self::sumOf($modifiers, ModifierType::DamageBonus)),
            mitigationPermille: min(
                $this->rules->mitigationCapPermille,
                max(0, self::scale($endurance, $this->rules->mitigationPermillePer1000Endurance) + self::sumOf($modifiers, ModifierType::MitigationBonus)),
            ),
            extraTurnPermille: min(
                $this->rules->extraTurnCapPermille,
                max(0, self::scale($dexterity, $this->rules->extraTurnPermillePer1000Dexterity) + self::sumOf($modifiers, ModifierType::ExtraTurnBonus)),
            ),
            dodgePermille: min(
                $this->rules->dodgeCapPermille,
                max(0, self::scale($mobility, $this->rules->dodgePermillePer1000Mobility) + self::sumOf($modifiers, ModifierType::DodgeBonus)),
            ),
        );
    }

    /**
     * Direct : le catalogue écrit déjà des valeurs de combattant, voir le docblock d'`Enemy`.
     * Aucune dérivation à faire ici — mais pas « aucun plafond à réappliquer » : les trois
     * mêmes refus que {@see CombatRules} impose au combattant dérivé (mitigation, tour
     * supplémentaire et esquive strictement sous 1000 ‰) sont portés par {@see EnemyCatalog},
     * pas par cette méthode ni par `CombatSection`, qui ne borne les trois champs que par le
     * bas — un ennemi entre dans la même boucle par la même porte qu'un joueur, il ne doit
     * pas échapper à l'invariant qui la garde.
     *
     * **Ne lit aucun modificateur (#224), et ce n'est pas une asymétrie à corriger.** Un
     * ennemi du catalogue n'a ni équipement ni compétences ; seul `forPlayer()` a une raison
     * d'interroger {@see ModifierResolver}.
     */
    public function forEnemy(Enemy $enemy): Fighter
    {
        return new Fighter($enemy->hp, $enemy->damage, $enemy->mitigationPermille, $enemy->extraTurnPermille, $enemy->dodgePermille);
    }

    /**
     * La composition retenue pour plusieurs modificateurs du même type : la somme — voir le
     * docblock de la classe pour pourquoi, et pourquoi {@see Modifier::$discipline} n'entre
     * pas en ligne de compte ici.
     *
     * @param list<Modifier> $modifiers
     */
    private static function sumOf(array $modifiers, ModifierType $type): int
    {
        $total = 0;

        foreach ($modifiers as $modifier) {
            if ($modifier->type === $type) {
                $total += $modifier->value;
            }
        }

        return $total;
    }

    private static function scale(int $total, int $permille): int
    {
        return intdiv($total * $permille, self::PERMILLE);
    }
}
