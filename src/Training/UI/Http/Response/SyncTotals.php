<?php

declare(strict_types=1);

namespace App\Training\UI\Http\Response;

use App\Training\Application\SessionCompletion;

/**
 * Le raccourci de l'écran de résumé, dérivé de la timeline et de rien d'autre.
 *
 * Les bornes se lisent aux deux extrémités de la liste : le premier workout crédité sait
 * d'où le joueur partait, le dernier où il est arrivé. C'est exactement ce qui garantit que
 * `totals` ne peut pas diverger d'`imported` — il n'a pas d'autre source.
 */
final readonly class SyncTotals
{
    private function __construct(
        public int $levelBefore,
        public int $levelAfter,
        public int $xpBefore,
        public int $xpAfter,
        public int $xpAwarded,
        public int $workoutCount,
    ) {
    }

    /**
     * @param list<SessionCompletion> $imported dans l'ordre chronologique
     */
    public static function of(array $imported): ?self
    {
        if ([] === $imported) {
            return null;
        }

        $first = $imported[0]->reward;
        $last = $imported[array_key_last($imported)]->reward;

        return new self(
            $first->levelBefore,
            $last->level,
            // `totalXp` est l'état d'**arrivée** de chaque récompense : le point de départ
            // du lot est donc celui du premier workout, moins ce qu'il a lui-même rapporté.
            // Le déduire ainsi plutôt que de le lire ailleurs est ce qui empêche la
            // divergence.
            $first->totalXp - $first->xpAwarded,
            $last->totalXp,
            array_sum(array_map(static fn (SessionCompletion $credited): int => $credited->reward->xpAwarded, $imported)),
            \count($imported),
        );
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'levelBefore' => $this->levelBefore,
            'levelAfter' => $this->levelAfter,
            'xpBefore' => $this->xpBefore,
            'xpAfter' => $this->xpAfter,
            'xpAwarded' => $this->xpAwarded,
            'workoutCount' => $this->workoutCount,
        ];
    }
}
