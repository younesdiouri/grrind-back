<?php

declare(strict_types=1);

namespace App\Engagement\Domain;

use InvalidArgumentException;
use LogicException;

/**
 * #35 : une division par ligue, effectif libre. Le meilleur peut monter même seul ;
 * les égalités partagent le rang et ne sont jamais départagées par UUID.
 * La chance de loot récompense le statut sans augmenter le score de la ligue elle-même.
 */
final readonly class LeagueRules
{
    public function __construct(public int $movementPercent, public int $minimumPlayersForRelegation, public int $lootLuckPerTierPercent)
    {
        if ($movementPercent < 1 || $movementPercent > 49 || $minimumPlayersForRelegation < 2 || $lootLuckPerTierPercent < 0 || $lootLuckPerTierPercent > 50) {
            throw new InvalidArgumentException('Ligues : mouvement entre 1 et 49 %, effectif minimum ≥ 2, chance par palier entre 0 et 50.');
        }
    }

    /** @param array<string, mixed> $snapshot */
    public static function fromSnapshot(array $snapshot): self
    {
        $settings = $snapshot['league'] ?? null;
        if (!\is_array($settings) || !\is_int($settings['movement_percent'] ?? null) || !\is_int($settings['minimum_players_for_relegation'] ?? null) || !\is_int($settings['loot_luck_per_tier_percent'] ?? null)) {
            throw new LogicException('Le réglage publié "league" est absent ou invalide. Appliquer les migrations.');
        }

        return new self($settings['movement_percent'], $settings['minimum_players_for_relegation'], $settings['loot_luck_per_tier_percent']);
    }

    public function lootLuck(LeagueTier $tier): int
    {
        return $tier->order() * $this->lootLuckPerTierPercent;
    }

    /**
     * @param array<string, array{tier: LeagueTier, score: int}> $players
     *
     * @return array<string, array{tier: LeagueTier, score: int, rank: ?int, participantCount: int, nextTier: LeagueTier, movement: LeagueMovement}>
     */
    public function evaluate(array $players): array
    {
        $results = [];
        foreach (LeagueTier::cases() as $tier) {
            $division = array_filter($players, static fn (array $player): bool => $player['tier'] === $tier);
            uasort($division, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
            $count = \count($division);
            $places = max(1, intdiv($count * $this->movementPercent, 100));
            $position = $rank = 0;
            $previous = null;
            foreach ($division as $id => $player) {
                ++$position;
                $score = max(0, $player['score']);
                if ($score !== $previous) {
                    $rank = $position;
                }
                $step = match (true) {
                    0 === $score => -1,
                    $rank <= $places => 1,
                    $count >= $this->minimumPlayersForRelegation && $rank > $count - $places => -1,
                    default => 0,
                };
                $next = $tier->shift($step);
                $results[$id] = ['tier' => $tier, 'score' => $score, 'rank' => 0 === $score ? null : $rank, 'participantCount' => $count, 'nextTier' => $next, 'movement' => LeagueMovement::between($tier, $next)];
                $previous = $score;
            }
        }

        return $results;
    }
}
