<?php

declare(strict_types=1);

namespace App\Shared\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Les agrégats du radar, sans écritures ni plans privés. Community ne peut pas importer
 * le provider de Progression : ce port traverse cette frontière, comme PublicInventories.
 * L'appelant doit d'abord vérifier la visibilité du joueur.
 */
interface PlayerWeekProgress
{
    /** @return array<string, mixed> */
    public function of(Uuid $playerId): array;
}
