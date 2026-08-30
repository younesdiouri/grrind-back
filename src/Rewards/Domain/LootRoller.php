<?php

declare(strict_types=1);

namespace App\Rewards\Domain;

use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Modifier\Modifier;
use App\Shared\Domain\Modifier\ModifierType;
use LogicException;
use Random\Randomizer;

/**
 * Le tirage de loot. **Fonction pure** : une table, les modificateurs déjà résolus du
 * joueur, un `Randomizer` grainé entrent, un {@see LootRollOutcome} sort — aucune base,
 * aucune horloge, aucun appel global à `random_int`. Même geste que
 * {@see \App\Combat\Domain\BattleSimulator} : le `Randomizer` entre par la signature, la
 * graine qui l'a construit est l'affaire de l'appelant (#226, #227), qui la stocke aussi
 * dans la ligne d'audit {@see LootRoll} — `Xoshiro256StarStar` grainé par `random_bytes(32)`,
 * jamais un hash d'une chaîne, voir le docblock de `Battle` pour ce que ça a coûté au #209.
 *
 * **Un seul tirage rend les objets *et* les pièces.** Les deux jets suivent, dans cet
 * ordre fixe, sur le même `Randomizer` : le jet pondéré qui choisit une entrée (un objet ou
 * « rien »), puis le jet uniforme qui choisit le montant de pièces dans la bande de la
 * table. Un seul événement de jeu, une seule graine, une seule ligne d'audit — jamais deux
 * jets séparés pour deux occasions de diverger.
 *
 * ## Départager les tables de séance éligibles (#28, après le #27)
 *
 * `loot.yaml` laisse volontairement les seuils de plusieurs tables se recouvrir — voir son
 * docblock — et ne tranche pas laquelle s'applique quand plusieurs le sont à la fois.
 * {@see rollForWorkout()} retient **la plus exigeante des tables éligibles** : celle dont
 * `minimumLevel` est le plus haut, et à égalité, celle dont `minimumDurationMinutes` est le
 * plus haut. C'est le choix qui a du sens de jeu — une séance qui dépasse déjà les seuils
 * d'un palier avancé ne doit pas retomber sur les récompenses d'un palier débutant — et il
 * est déterministe : deux appels sur les mêmes tables et la même séance retiennent toujours
 * la même.
 *
 * ## Aucune table éligible ne tire rien du tout (#28, après le #27)
 *
 * {@see rollForWorkout()} rend `null` quand aucune table n'est éligible — une séance sous
 * les 20 minutes de la première table livrée, par exemple. **Rien, pas même une pièce** :
 * la bande de pièces appartient à la table ({@see LootTable::$coins}), pas à la séance, et
 * il n'existe aucune bande « par défaut » à piocher en l'absence de table. Une séance trop
 * courte pour mériter un objet ne mérite pas non plus une poignée de pièces qu'aucune
 * table n'a promise.
 *
 * ## Un adversaire sans table dédiée (#28, après le #27)
 *
 * {@see LootTables::forAdversary()} rend `null` pour une clé sans table — aucun adversaire
 * livré n'est dans ce cas, {@see \App\Tests\Shared\Config\RewardsCoverageTest} le prouve —
 * mais {@see rollForAdversary()} doit s'en accommoder proprement le jour où `combat.yaml`
 * ouvre un adversaire avant que sa table n'existe dans `loot.yaml` : `null` en retour,
 * jamais une exception pour un cas que le catalogue autorise déjà.
 *
 * ## `LOOT_LUCK` déplace les poids, jamais le nombre de tirages
 *
 * `LOOT_LUCK` est déjà résolu en `list<Modifier>` par l'appelant — {@see
 * \App\Shared\Application\ModifierResolver}, comme partout — et cette classe en est
 * l'unique consommateur, voir le docblock de {@see ModifierType}. La composition de
 * plusieurs sources est la **somme**, même choix et même raison qu'{@see
 * \App\Combat\Application\FighterFactory::sumOf()} : chaque contribution reste vraie
 * isolément, la composition ne dépend pas de l'ordre dans lequel le resolver les a rendus.
 *
 * La somme est ensuite ramenée dans les bornes de {@see LootLuckRules} — jamais appliquée
 * brute — puis sert à recalculer le poids de chaque entrée **à objet** d'une table :
 * `poids + poids × luck ÷ 100`, en entier tronqué, jamais un flottant sur une valeur de
 * jeu. **L'entrée « rien » ne bouge jamais** : c'est elle qui reste le point de référence
 * de la table, et voir le docblock de {@see LootLuckRules} pour pourquoi ce choix suffit,
 * conjugué au plancher à zéro, à ne jamais donner une table à somme de poids nulle. Un seul
 * jet pondéré est joué sur les poids ainsi recalculés — jamais un second tirage, jamais un
 * tirage de plus par palier de chance : c'est la distribution qui change de forme, pas le
 * nombre de fois qu'on l'interroge.
 */
final readonly class LootRoller
{
    public function __construct(
        private LootLuckRules $luck,
    ) {
    }

    /**
     * `null` quand aucune table n'est éligible — voir le docblock de la classe.
     *
     * @param list<Modifier> $modifiers déjà résolus par l'appelant, voir le docblock de la classe
     */
    public function rollForWorkout(LootTables $tables, Discipline $discipline, int $durationMinutes, int $level, array $modifiers, Randomizer $randomizer): ?LootRollOutcome
    {
        $table = self::mostExigentEligible($tables->workoutTables(), $discipline, $durationMinutes, $level);

        if (null === $table) {
            return null;
        }

        return $this->roll($table->key, $tables->version, $table->table, $modifiers, $randomizer);
    }

    /**
     * `null` quand l'adversaire n'a pas de table dédiée — voir le docblock de la classe.
     *
     * @param list<Modifier> $modifiers déjà résolus par l'appelant, voir le docblock de la classe
     */
    public function rollForAdversary(LootTables $tables, string $adversaryKey, array $modifiers, Randomizer $randomizer): ?LootRollOutcome
    {
        $table = $tables->forAdversary($adversaryKey);

        if (null === $table) {
            return null;
        }

        return $this->roll($adversaryKey, $tables->version, $table, $modifiers, $randomizer);
    }

    /**
     * La plus exigeante des tables éligibles — voir le docblock de la classe. `null` si
     * aucune ne l'est.
     *
     * @param list<WorkoutLootTable> $tables
     */
    private static function mostExigentEligible(array $tables, Discipline $discipline, int $durationMinutes, int $level): ?WorkoutLootTable
    {
        $best = null;

        foreach ($tables as $table) {
            if (!$table->isEligibleFor($discipline, $durationMinutes, $level)) {
                continue;
            }

            if (null === $best
                || $table->minimumLevel > $best->minimumLevel
                || ($table->minimumLevel === $best->minimumLevel && $table->minimumDurationMinutes > $best->minimumDurationMinutes)
            ) {
                $best = $table;
            }
        }

        return $best;
    }

    /**
     * @param list<Modifier> $modifiers
     */
    private function roll(string $tableKey, int $tableVersion, LootTable $table, array $modifiers, Randomizer $randomizer): LootRollOutcome
    {
        $effectiveLootLuckPercent = $this->luck->clamp(self::sumOf($modifiers, ModifierType::LootLuck));

        $weights = array_map(
            static fn (LootEntry $entry): int => null === $entry->itemKey
                ? $entry->weight
                : $entry->weight + self::scale($entry->weight, $effectiveLootLuckPercent),
            $table->entries,
        );

        $totalWeight = array_sum($weights);
        $itemRoll = $randomizer->getInt(0, $totalWeight - 1);
        $itemKey = self::pick($table->entries, $weights, $itemRoll);

        $coins = $randomizer->getInt($table->coins->minimum, $table->coins->maximum);

        return new LootRollOutcome(
            tableKey: $tableKey,
            tableVersion: $tableVersion,
            effectiveLootLuckPercent: $effectiveLootLuckPercent,
            itemRoll: $itemRoll,
            itemTotalWeight: $totalWeight,
            items: null === $itemKey ? [] : [$itemKey],
            coins: $coins,
        );
    }

    /**
     * Parcourt les entrées dans leur ordre de déclaration — le même ordre à chaque appel,
     * ce qui rend le tirage reproductible pour un `$roll` donné. `$roll` est strictement
     * sous la somme des poids fournis en entrée : la boucle désigne toujours une entrée
     * avant sa dernière itération, l'exception est un filet de sécurité qui ne s'atteint
     * jamais si {@see roll()} l'appelle comme conçu.
     *
     * @param list<LootEntry> $entries
     * @param list<int>       $weights même index que `$entries`
     */
    private static function pick(array $entries, array $weights, int $roll): ?string
    {
        $cumulative = 0;

        foreach ($entries as $index => $entry) {
            $cumulative += $weights[$index];

            if ($roll < $cumulative) {
                return $entry->itemKey;
            }
        }

        throw new LogicException('Le tirage pondéré est sorti sans désigner d\'entrée : la somme des poids ne couvre pas le roll fourni.');
    }

    /**
     * La composition retenue pour plusieurs `LOOT_LUCK` actifs à la fois : la somme — voir
     * le docblock de la classe.
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

    private static function scale(int $weight, int $percent): int
    {
        return intdiv($weight * $percent, 100);
    }
}
