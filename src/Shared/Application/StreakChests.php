<?php

declare(strict_types=1);

namespace App\Shared\Application;

use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * `Engagement` décide qu'une semaine de régularité rapporte un coffre (#286), `Rewards`
 * reste seul à écrire l'inventaire — même partage que {@see AlamRewards} pour le raid.
 * L'unicité de l'attribution est tenue côté `Engagement`, qui l'audite.
 */
interface StreakChests
{
    /** Crédite un exemplaire du coffre `$itemKey` et le décrit pour la mise en scène. */
    public function grant(Uuid $player, string $itemKey, DateTimeImmutable $obtainedAt): DroppedItem;
}
