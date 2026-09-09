<?php

declare(strict_types=1);

namespace App\Shared\Application;

use Symfony\Component\Uid\Uuid;

/** Inventaire de présentation uniquement ; l'appelant doit vérifier la visibilité du joueur. */
interface PublicInventories
{
    /**
     * @return array{equipment: array<string, array<string, mixed>|null>, items: list<array<string, mixed>>}
     */
    public function of(Uuid $playerId): array;
}
