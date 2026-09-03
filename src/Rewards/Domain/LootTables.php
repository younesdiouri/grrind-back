<?php

declare(strict_types=1);

namespace App\Rewards\Domain;

use App\Shared\Application\GameRulesets;
use App\Shared\Domain\Activity\Discipline;
use InvalidArgumentException;

/**
 * Les tables de tirage, chargées depuis le snapshot de jeu publié — trois origines, trois
 * jeux de tables : la séance ({@see WorkoutLootTable}, gardée par une éligibilité),
 * l'adversaire et le coffre (#230), chacun une {@see LootTable} par clé — d'ennemi ou de
 * boss de le snapshot publié pour l'un, d'objet `kind: CHEST` du snapshot publié pour l'autre — sans
 * condition ni pour l'un ni pour l'autre : ce qui a été choisi *est* la condition.
 *
 * `$version` est la version de la table, indépendante du `rulesetVersion` global : un
 * tirage audité (#28) stocke laquelle a produit son roll, et rééquilibrer les poids d'une
 * table n'a pas à invalider l'historique d'XP qui vit sous le `rulesetVersion`.
 *
 * ## Pourquoi les clés inconnues ne sont pas refusées par `LootSection`
 *
 * Un objet inexistant ou une clé d'adversaire inconnue sont des références vers une autre
 * partie du snapshot publié. Les croiser ici garde `Rewards` indépendant de `Combat` : les
 * deux tableaux restent de la donnée, jamais un objet importé d'un autre module. La
 * publication admin reconstruit cette classe avant commit, et la couverture runtime
 * construit la même forme depuis `GameRulesets`.
 *
 * ## Une clé de table de coffre doit être un objet `kind: CHEST` connu (#230)
 *
 * Même geste que pour l'adversaire — {@see self::__construct()} refuse une clé qui ne
 * désigne aucun coffre du catalogue — mais avec une nuance : `$items` porte ici *tous* les
 * objets, pas seulement les coffres, donc la clé doit en plus être du bon `kind`. Une table
 * pour un objet `EQUIPMENT` est donc refusée exactement comme une table pour une clé
 * inexistante, jamais une table « ignorée » en silence.
 *
 * ## Une table de coffre ne peut pas contenir de coffre (#230)
 *
 * Un coffre s'ouvre, il ne tombe jamais d'un tirage — voir « Personne ne donne de coffre en
 * dehors de la boutique » dans le ticket #230. Une entrée de table de coffre qui référence
 * un objet `kind: CHEST` mentirait donc sur ce que le jeu permet ; {@see self::table()} le
 * refuse au chargement plutôt que de laisser {@see LootRoller} le tirer un jour.
 */
final class LootTables
{
    public int $version;

    /** @var list<WorkoutLootTable> */
    private array $workoutTables;

    /** @var array<string, LootTable> */
    private array $adversaryTables;

    /** @var array<string, LootTable> */
    private array $chestTables;

    private ?GameRulesets $rulesets;

    private ?self $currentTables = null;

    private ?int $runtimeRevision = null;

    /**
     * @param list<array{key: string, eligibility: array{disciplines: list<string>, minimum_duration_minutes: int, minimum_level: int}, coins: array{minimum: int, maximum: int}, entries: list<array{item?: string, weight: int}>}> $workout
     * @param list<array{key: string, coins: array{minimum: int, maximum: int}, entries: list<array{item?: string, weight: int}>}>                                                                                                   $adversary
     * @param list<array{key: string, coins: array{minimum: int, maximum: int}, entries: list<array{item?: string, weight: int}>}>                                                                                                   $chest     une table par clé de coffre (#230) — même forme que `$adversary`
     * @param list<array{key: string, kind?: string}>                                                                                                                                                                                $items     le catalogue brut du snapshot publié — la clé, et depuis le #230 `kind`, importent ici
     * @param list<array{key: string}>                                                                                                                                                                                               $enemies   ennemis du snapshot publié
     * @param list<array{key: string}>                                                                                                                                                                                               $bosses    boss du snapshot publié
     *
     * @throws InvalidArgumentException les tables ne tiennent pas debout ; la construction du service s'arrête là
     */
    public function __construct(int $version, array $workout, array $adversary, array $chest, array $items, array $enemies, array $bosses, ?GameRulesets $rulesets = null)
    {
        $this->rulesets = $rulesets;
        if (null !== $rulesets) {
            $this->version = 0;
            $this->workoutTables = [];
            $this->adversaryTables = [];
            $this->chestTables = [];

            return;
        }
        if ($version < 1) {
            throw new InvalidArgumentException(\sprintf('La version des tables de tirage doit être au moins 1, %d demandée.', $version));
        }

        $this->version = $version;

        $knownItemKeys = array_flip(array_column($items, 'key'));
        // Le sous-ensemble des coffres, pour les deux refus du docblock de la classe : une
        // clé de table de coffre qui ne désigne pas un coffre, et une entrée de table de
        // coffre qui en désigne un.
        $knownChestItemKeys = array_flip(array_column(
            array_filter($items, static fn (array $item): bool => ItemKind::Chest->value === ($item['kind'] ?? ItemKind::Equipment->value)),
            'key',
        ));
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

        $chestTables = [];

        foreach ($chest as $entry) {
            if (!isset($knownChestItemKeys[$entry['key']])) {
                throw new InvalidArgumentException(\sprintf('Table de tirage de coffre pour une clé qui n\'est pas un coffre du catalogue : "%s".', $entry['key']));
            }

            if (isset($chestTables[$entry['key']])) {
                throw new InvalidArgumentException(\sprintf('Deux tables de tirage pour le coffre "%s".', $entry['key']));
            }

            // `$knownChestItemKeys` en quatrième argument : voir « Une table de coffre ne
            // peut pas contenir de coffre » dans le docblock de la classe.
            $chestTables[$entry['key']] = self::table($entry['key'], $entry, $knownItemKeys, $knownChestItemKeys);
        }

        $this->workoutTables = $workoutTables;
        $this->adversaryTables = $adversaryTables;
        $this->chestTables = $chestTables;
    }

    public static function runtime(GameRulesets $rulesets): self
    {
        return new self(1, [], [], [], [], [], [], $rulesets);
    }

    /**
     * @return list<WorkoutLootTable>
     */
    public function workoutTables(): array
    {
        if (null !== $this->rulesets) {
            return $this->current()->workoutTables();
        }

        return $this->workoutTables;
    }

    public function version(): int
    {
        return null === $this->rulesets ? $this->version : $this->current()->version();
    }

    /** `null` si cet adversaire n'a pas de table dédiée — voir `RewardsCoverageTest` pour la couverture réellement livrée. */
    public function forAdversary(string $key): ?LootTable
    {
        if (null !== $this->rulesets) {
            return $this->current()->forAdversary($key);
        }

        return $this->adversaryTables[$key] ?? null;
    }

    /**
     * `null` si ce coffre n'a pas de table dédiée — un cas que `RewardsCoverageTest` ne
     * rencontre jamais en production (#230), même remarque que {@see forAdversary()}.
     */
    public function forChest(string $key): ?LootTable
    {
        if (null !== $this->rulesets) {
            return $this->current()->forChest($key);
        }

        return $this->chestTables[$key] ?? null;
    }

    private function current(): self
    {
        $revision = $this->rulesets?->revision();
        \assert(\is_int($revision));
        if (null !== $this->currentTables && $revision === $this->runtimeRevision) {
            return $this->currentTables;
        }
        $snapshot = $this->rulesets?->snapshot();
        \assert(\is_array($snapshot));
        /** @var array{loot: array{version: int, workout: list<array{active?: bool}>, adversary: list<array{active?: bool}>, chest: list<array{active?: bool}>}, items: list<array{key: string, kind?: string}>, combat: array{enemies: list<array{key: string}>, bosses: list<array{key: string}>}} $snapshot */
        /** @var array{version: int, workout: list<array{key: string, eligibility: array{disciplines: list<string>, minimum_duration_minutes: int, minimum_level: int}, coins: array{minimum: int, maximum: int}, entries: list<array{item?: string, weight: int}>}>, adversary: list<array{key: string, coins: array{minimum: int, maximum: int}, entries: list<array{item?: string, weight: int}>}>, chest: list<array{key: string, coins: array{minimum: int, maximum: int}, entries: list<array{item?: string, weight: int}>}>} $loot */ $loot = $snapshot['loot'];
        /** @var list<array{key: string, rarity: string, slot?: string, kind?: string, price_coins: int, modifiers: list<array{type: string, value: int, discipline?: string}>}> $items */ $items = $snapshot['items'];
        /** @var list<array{key: string}> $enemies */ $enemies = $snapshot['combat']['enemies'];
        /** @var list<array{key: string}> $bosses */ $bosses = $snapshot['combat']['bosses'];

        $this->runtimeRevision = $revision;

        return $this->currentTables = new self(
            $loot['version'],
            array_values(array_filter($loot['workout'], static fn (array $table): bool => $table['active'] ?? true)),
            array_values(array_filter($loot['adversary'], static fn (array $table): bool => $table['active'] ?? true)),
            array_values(array_filter($loot['chest'], static fn (array $table): bool => $table['active'] ?? true)),
            $items,
            $enemies,
            $bosses,
        );
    }

    /**
     * @param array{coins: array{minimum: int, maximum: int}, entries: list<array{item?: string, weight: int}>} $entry
     * @param array<string, int>                                                                                $knownItemKeys
     * @param array<string, int>                                                                                $forbiddenChestItemKeys vide sauf pour une table de coffre — voir le docblock de la classe
     */
    private static function table(string $context, array $entry, array $knownItemKeys, array $forbiddenChestItemKeys = []): LootTable
    {
        $entries = array_map(
            static function (array $rawEntry) use ($context, $knownItemKeys, $forbiddenChestItemKeys): LootEntry {
                $itemKey = $rawEntry['item'] ?? null;

                if (null !== $itemKey && !isset($knownItemKeys[$itemKey])) {
                    throw new InvalidArgumentException(\sprintf('"%s" pointe vers un objet inexistant : "%s".', $context, $itemKey));
                }

                if (null !== $itemKey && isset($forbiddenChestItemKeys[$itemKey])) {
                    throw new InvalidArgumentException(\sprintf('"%s" fait tomber le coffre "%s" : une table de coffre ne peut pas contenir de coffre.', $context, $itemKey));
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
