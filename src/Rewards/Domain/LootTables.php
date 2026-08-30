<?php

declare(strict_types=1);

namespace App\Rewards\Domain;

use App\Shared\Domain\Activity\Discipline;
use InvalidArgumentException;

/**
 * Les tables de tirage, chargées depuis `config/game/v1/loot.yaml` — deux origines, deux
 * jeux de tables : la séance ({@see WorkoutLootTable}, gardée par une éligibilité) et
 * l'adversaire (une {@see LootTable} par clé d'ennemi ou de boss de `combat.yaml`, sans
 * condition : l'adversaire choisi *est* la condition).
 *
 * `$version` est la version de la table, indépendante du `rulesetVersion` global : un
 * tirage audité (#28) stocke laquelle a produit son roll, et rééquilibrer les poids d'une
 * table n'a pas à invalider l'historique d'XP qui vit sous le `rulesetVersion`.
 *
 * ## Pourquoi les clés inconnues ne sont pas refusées par `LootSection`
 *
 * Un objet inexistant ou une clé d'adversaire inconnue sont des références **vers un autre
 * fichier** — `items.yaml`, `combat.yaml` — et `GameBalanceLoader` valide chaque fichier
 * indépendamment des autres : `LootSection` ne voit jamais leur contenu. C'est exactement
 * la limite déjà documentée par {@see \App\Progression\Infrastructure\Config\AttributeSplitSection}
 * pour `xp.yaml` et `attributes.yaml`, et la même réponse s'applique : le croisement se
 * fait ici, à la construction réelle du service, câblée par `services.yaml` avec les
 * paramètres bruts des sections concernées — `%game.items.items%`, `%game.combat.enemies%`,
 * `%game.combat.bosses%` — et non par un import d'`ItemCatalog` ni d'`EnemyCatalog` :
 * Deptrac interdit à `Rewards` de connaître `Combat`, et ces deux tableaux ne sont que de
 * la donnée, jamais un objet de leur domaine. La couverture réelle est prouvée par
 * `RewardsCoverageTest`, qui construit cette classe depuis les paramètres du conteneur
 * comme `services.yaml` le fait réellement — même geste qu'`AttributeSplitCoverageTest`.
 */
final readonly class LootTables
{
    public int $version;

    /** @var list<WorkoutLootTable> */
    private array $workoutTables;

    /** @var array<string, LootTable> */
    private array $adversaryTables;

    /**
     * @param list<array{key: string, eligibility: array{disciplines: list<string>, minimum_duration_minutes: int, minimum_level: int}, coins: array{minimum: int, maximum: int}, entries: list<array{item?: string, weight: int}>}> $workout
     * @param list<array{key: string, coins: array{minimum: int, maximum: int}, entries: list<array{item?: string, weight: int}>}>                                                                                                   $adversary
     * @param list<array{key: string}>                                                                                                                                                                                               $items     le catalogue brut d'`items.yaml` — seule la clé importe ici
     * @param list<array{key: string}>                                                                                                                                                                                               $enemies   `game.combat.enemies`
     * @param list<array{key: string}>                                                                                                                                                                                               $bosses    `game.combat.bosses`
     *
     * @throws InvalidArgumentException les tables ne tiennent pas debout ; la construction du service s'arrête là
     */
    public function __construct(int $version, array $workout, array $adversary, array $items, array $enemies, array $bosses)
    {
        if ($version < 1) {
            throw new InvalidArgumentException(\sprintf('La version des tables de tirage doit être au moins 1, %d demandée.', $version));
        }

        $this->version = $version;

        $knownItemKeys = array_flip(array_column($items, 'key'));
        $knownAdversaryKeys = array_flip([...array_column($enemies, 'key'), ...array_column($bosses, 'key')]);

        $workoutTables = [];
        $seenWorkoutKeys = [];

        foreach ($workout as $entry) {
            if (isset($seenWorkoutKeys[$entry['key']])) {
                throw new InvalidArgumentException(\sprintf('Deux tables de séance pour la clé "%s".', $entry['key']));
            }

            $seenWorkoutKeys[$entry['key']] = true;

            $disciplines = array_map(
                static fn (string $discipline): Discipline => Discipline::tryFrom($discipline)
                    ?? throw new InvalidArgumentException(\sprintf('"%s" cible une discipline inconnue : "%s".', $entry['key'], $discipline)),
                $entry['eligibility']['disciplines'],
            );

            $workoutTables[] = new WorkoutLootTable(
                $entry['key'],
                $disciplines,
                $entry['eligibility']['minimum_duration_minutes'],
                $entry['eligibility']['minimum_level'],
                self::table($entry['key'], $entry, $knownItemKeys),
            );
        }

        $adversaryTables = [];

        foreach ($adversary as $entry) {
            if (!isset($knownAdversaryKeys[$entry['key']])) {
                throw new InvalidArgumentException(\sprintf('Table de tirage pour une clé d\'adversaire inconnue : "%s".', $entry['key']));
            }

            if (isset($adversaryTables[$entry['key']])) {
                throw new InvalidArgumentException(\sprintf('Deux tables de tirage pour l\'adversaire "%s".', $entry['key']));
            }

            $adversaryTables[$entry['key']] = self::table($entry['key'], $entry, $knownItemKeys);
        }

        $this->workoutTables = $workoutTables;
        $this->adversaryTables = $adversaryTables;
    }

    /**
     * @return list<WorkoutLootTable>
     */
    public function workoutTables(): array
    {
        return $this->workoutTables;
    }

    /** `null` si cet adversaire n'a pas de table dédiée — voir `RewardsCoverageTest` pour la couverture réellement livrée. */
    public function forAdversary(string $key): ?LootTable
    {
        return $this->adversaryTables[$key] ?? null;
    }

    /**
     * @param array{coins: array{minimum: int, maximum: int}, entries: list<array{item?: string, weight: int}>} $entry
     * @param array<string, int>                                                                                $knownItemKeys
     */
    private static function table(string $context, array $entry, array $knownItemKeys): LootTable
    {
        $entries = array_map(
            static function (array $rawEntry) use ($context, $knownItemKeys): LootEntry {
                $itemKey = $rawEntry['item'] ?? null;

                if (null !== $itemKey && !isset($knownItemKeys[$itemKey])) {
                    throw new InvalidArgumentException(\sprintf('"%s" pointe vers un objet inexistant : "%s".', $context, $itemKey));
                }

                return new LootEntry($itemKey, $rawEntry['weight']);
            },
            $entry['entries'],
        );

        return new LootTable(
            new CoinBand($entry['coins']['minimum'], $entry['coins']['maximum']),
            $entries,
        );
    }
}
