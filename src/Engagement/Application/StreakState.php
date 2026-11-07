<?php

declare(strict_types=1);

namespace App\Engagement\Application;

/**
 * Le streak du joueur à son jour local (#346), tel que le client le reçoit — sur
 * `GET /api/streak` comme en réponse au pointage.
 */
final readonly class StreakState
{
    public function __construct(
        /** Jours pointés consécutifs ; aujourd'hui compris s'il l'est déjà. */
        public int $days,
        public bool $checkedInToday,
        /** Jours de la semaine en cours (lundi → aujourd'hui) avec au moins une séance créditée. */
        public int $sportDaysThisWeek,
        /** Le palier du coffre de cette semaine si elle s'arrêtait là : `COMMON`, `RARE` ou `LEGENDARY`. */
        public string $chestRarity,
    ) {
    }

    /** @return array{days: int, checkedInToday: bool, sportDaysThisWeek: int, chestRarity: string} */
    public function toArray(): array
    {
        return [
            'days' => $this->days,
            'checkedInToday' => $this->checkedInToday,
            'sportDaysThisWeek' => $this->sportDaysThisWeek,
            'chestRarity' => $this->chestRarity,
        ];
    }
}
