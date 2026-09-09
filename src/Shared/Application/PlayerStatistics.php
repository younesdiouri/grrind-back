<?php

declare(strict_types=1);

namespace App\Shared\Application;

use Symfony\Component\Uid\Uuid;

/** Le combattant expliqué, partagé par l'inventaire et le profil sans dépendre de Combat. */
interface PlayerStatistics
{
    /**
     * @return array{attributes: array<string, array{base: int, equipmentBonus: int, effective: int}>, fighter: array<string, int>}
     */
    public function of(Uuid $playerId, PlayerProgression $progression): array;
}
