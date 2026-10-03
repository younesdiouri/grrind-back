<?php

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * L'état d'une série de régularité (#286) à un jour donné, tel que le client le reçoit —
 * dans le `RewardSummary` (avant/après une séance) comme sur `GET /api/streak`.
 *
 * Dans `Shared` parce que `Engagement` le calcule et que `Training` le relaie dans la
 * mise en scène, sans que l'un nomme l'autre — même raison que {@see SessionDrop}.
 */
final readonly class StreakState
{
    public function __construct(
        /** Premier jour sportif de la série en cours, `AAAA-MM-JJ` local ; `null` sans série. */
        public ?string $startedOn,
        /** Jours écoulés depuis `startedOn`, aujourd'hui compris. Zéro sans série. */
        public int $days,
        /** Semaines de 7 jours de la série acquises à 6 jours sportifs ou plus. */
        public int $weeksCompleted,
        /** Jours sportifs parmi les 7 derniers, aujourd'hui compris. */
        public int $sportDaysInLast7,
        /** La rareté du prochain coffre : `COMMON`, `RARE`, `EPIC` puis `LEGENDARY` pour toujours. */
        public string $nextChestRarity,
    ) {
    }

    /** @return array{startedOn: ?string, days: int, weeksCompleted: int, sportDaysInLast7: int, nextChestRarity: string} */
    public function toArray(): array
    {
        return [
            'startedOn' => $this->startedOn,
            'days' => $this->days,
            'weeksCompleted' => $this->weeksCompleted,
            'sportDaysInLast7' => $this->sportDaysInLast7,
            'nextChestRarity' => $this->nextChestRarity,
        ];
    }
}
