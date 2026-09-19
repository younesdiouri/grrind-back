<?php

declare(strict_types=1);

namespace App\Shared\Application;

use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Le raid arbitre le tirage, Rewards reste seul propriétaire de l'inventaire.
 * Une provenance run/rencontre/joueur rend une reprise indépendante du rejeu HTTP.
 * Le ruleset fourni est celui figé par l'édition, jamais le publié courant.
 */
interface AlamRewards
{
    /** @param array<string, int> $items */
    public function grant(Uuid $runId, int $encounter, Uuid $userId, array $items, DateTimeImmutable $occurredAt, GameRulesets $ruleset): void;
}
