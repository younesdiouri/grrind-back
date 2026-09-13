<?php

declare(strict_types=1);

namespace App\Admin\Domain;

use App\Combat\Domain\CombatRules;
use App\Combat\Domain\EnemyCatalog;
use App\Shared\Application\FrozenGameRulesets;
use App\Shared\Infrastructure\Config\GameRulesetVersion;
use InvalidArgumentException;

/**
 * Le coût est partagé par toute la matrice, jamais renouvelé par cellule.
 * Chaque cellule reçoit le même effectif et des graines distinctes, communes aux deux versions.
 *
 * @phpstan-import-type ProfileInput from GameDesignProfile
 * @phpstan-import-type Observation from GameDesignStatistics
 * @phpstan-import-type Summary from GameDesignStatistics
 */
final readonly class GameDesignCampaign
{
    public const int MAX_DUELS = 20000;

    public function __construct(private GameDesignCombat $combat)
    {
    }

    /** @param array{published: array<string,mixed>, draft: array<string,mixed>} $snapshots */
    public static function costPerPair(array $snapshots): int
    {
        $cost = 0;
        foreach ($snapshots as $snapshot) {
            /** @var array{combat: array{fighter: array<string,int>}} $snapshot */
            $cost += CombatRules::fromSnapshot($snapshot['combat']['fighter'])->maxAttacks;
        }

        return $cost;
    }

    /** @param array{published: array<string,mixed>, draft: array<string,mixed>} $snapshots */
    public static function maximumRepetitions(array $snapshots, int $profiles, int $enemies): int
    {
        if ($profiles < 1 || $profiles > 50 || $enemies < 1 || $enemies > 10) {
            return 0;
        }
        $cells = $profiles * $enemies;

        return min(1000, intdiv(GameDesignCombat::MAX_ATTEMPTS, self::costPerPair($snapshots) * $cells), intdiv(self::MAX_DUELS, 2 * $cells));
    }

    /** @param array{published: array<string,mixed>, draft: array<string,mixed>} $snapshots
     * @param list<ProfileInput> $profiles
     * @param list<string>       $enemies
     */
    public static function preflight(array $snapshots, array $profiles, array $enemies): void
    {
        foreach ($snapshots as $side => $snapshot) {
            $rules = new FrozenGameRulesets($snapshot, GameRulesetVersion::of($snapshot));
            $catalog = EnemyCatalog::runtime($rules);
            foreach ($enemies as $key) {
                if (null === $catalog->findAvailable($key) && null === $catalog->findAvailableBoss($key)) {
                    throw new InvalidArgumentException('Adversaire absent ou désactivé ('.$side.') : '.$key.'. Aucun duel lancé.');
                }
            }
            foreach ($profiles as $profile) {
                GameDesignInputs::fighter($profile, $rules);
            }
        }
    }

    /** @param array{published: array<string,mixed>, draft: array<string,mixed>} $snapshots
     * @param list<ProfileInput> $profiles
     * @param list<string>       $enemies
     *
     * @return array<string,mixed>
     */
    public function run(array $snapshots, array $profiles, array $enemies, int $repetitions, int $seed, ?int $target): array
    {
        if ($repetitions < 1 || $repetitions > self::maximumRepetitions($snapshots, \count($profiles), \count($enemies)) || \count(array_unique($enemies)) !== \count($enemies) || $seed < 0 || $seed > 2000000000 || (null !== $target && ($target < 0 || $target > 100))) {
            throw new InvalidArgumentException('Campagne hors budget : au plus 50 profils, 10 adversaires, 500000 tentatives et 20000 duels au total. Réduisez la matrice ou les répétitions.');
        }
        self::preflight($snapshots, $profiles, $enemies);
        $cells = [];
        $pooled = $byProfile = $byEnemy = ['published' => [], 'draft' => []];
        foreach ($profiles as $p => $profile) {
            foreach ($enemies as $e => $enemy) {
                $cellSeed = $seed + ($p * \count($enemies) + $e) * $repetitions;
                $series = $this->combat->compare($snapshots['published'], $snapshots['draft'], $profile, $enemy, null, $repetitions, $cellSeed, false);
                $cell = ['profileIndex' => $p, 'enemyIndex' => $e, 'seed' => $cellSeed];
                foreach (['published', 'draft'] as $side) {
                    /** @var non-empty-list<Observation> $observations */
                    $observations = $series[$side]['observations'];
                    $cell[$side] = GameDesignStatistics::summarize($observations);
                    $pooled[$side] = [...$pooled[$side], ...$observations];
                    $byProfile[$side][$p] = [...($byProfile[$side][$p] ?? []), ...$observations];
                    $byEnemy[$side][$e] = [...($byEnemy[$side][$e] ?? []), ...$observations];
                }
                $cells[] = $cell;
            }
        }
        $global = $profileRows = $enemyRows = [];
        foreach (['published', 'draft'] as $side) {
            /** @var non-empty-list<Observation> $observations */
            $observations = $pooled[$side];
            $global[$side] = GameDesignStatistics::summarize($observations);
            foreach ($profiles as $p => $profile) {
                /** @var non-empty-list<Observation> $observations */
                $observations = $byProfile[$side][$p];
                $profileRows[$p]['index'] = $p;
                $profileRows[$p]['name'] = $profile['name'];
                $profileRows[$p][$side] = GameDesignStatistics::summarize($observations);
            }
            foreach ($enemies as $e => $enemy) {
                /** @var non-empty-list<Observation> $observations */
                $observations = $byEnemy[$side][$e];
                $enemyRows[$e]['index'] = $e;
                $enemyRows[$e]['name'] = $enemy;
                $enemyRows[$e][$side] = GameDesignStatistics::summarize($observations);
            }
        }
        /** @var list<array{index:int,name:string,published:Summary,draft:Summary}> $profileRows */
        $profileRows = array_values($profileRows);
        $distribution = $dispersion = [];
        foreach (['published', 'draft'] as $side) {
            $rates = array_map(static fn (array $row): float => $row[$side]['winRate'], $profileRows);
            $histogram = array_fill(0, 10, 0);
            foreach ($rates as $rate) {
                ++$histogram[min(9, (int) floor($rate / 10))];
            }
            $mean = array_sum($rates) / \count($rates);
            $dispersion[$side] = sqrt(array_sum(array_map(static fn (float $rate): float => ($rate - $mean) ** 2, $rates)) / \count($rates));
            $distribution[$side] = $histogram;
        }
        usort($profileRows, static fn (array $a, array $b): int => $b['draft']['winRate'] <=> $a['draft']['winRate']);
        usort($enemyRows, static fn (array $a, array $b): int => $a['draft']['winRate'] <=> $b['draft']['winRate']);

        return ['cells' => $cells, 'global' => $global, 'profiles' => $profileRows, 'enemies' => $enemyRows, 'distribution' => $distribution, 'dispersion' => $dispersion, 'target' => $target, 'repetitions' => $repetitions, 'duels' => 2 * \count($profiles) * \count($enemies) * $repetitions, 'attemptBudget' => \count($profiles) * \count($enemies) * $repetitions * self::costPerPair($snapshots), 'versions' => ['published' => GameRulesetVersion::of($snapshots['published']), 'draft' => GameRulesetVersion::of($snapshots['draft'])]];
    }
}
