<?php

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Ce qu'une séance a fait à la série de régularité (#286), dans l'ordre de l'animation :
 * le jour sportif, la série avant, la série après, puis les coffres gagnés.
 *
 * `before` et `after` sont **calculés** par le serveur, jamais reconstitués côté client :
 * même règle que le palier de niveau sur {@see SessionReward}.
 */
final readonly class SessionStreak
{
    /**
     * @param list<DroppedItem> $chests vide le plus souvent ; un seul en régime normal
     */
    public function __construct(
        /** Le jour local du sport, `AAAA-MM-JJ`. */
        public string $sportDay,
        /** Ce jour atteint le seuil de durée, cette séance comprise. */
        public bool $dayCounted,
        public StreakState $before,
        public StreakState $after,
        public array $chests,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sportDay' => $this->sportDay,
            'dayCounted' => $this->dayCounted,
            'before' => $this->before->toArray(),
            'after' => $this->after->toArray(),
            'chests' => array_map(static fn (DroppedItem $chest): array => $chest->toArray(), $this->chests),
        ];
    }
}
