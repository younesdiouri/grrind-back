<?php

declare(strict_types=1);

namespace App\Shared\Application;

use Symfony\Component\Uid\Uuid;

/** Frontière Rewards → Combat : références portées, en batch, jamais le sac ni les statistiques. */
interface PlayerEquipment
{
    /**
     * Une version nulle signifie qu'aucun calque n'est livré pour cet objet.
     *
     * @param list<Uuid> $playerIds
     *
     * @return array<string, array<string, array{key: string, artworkVersion: ?string}>> UUID → emplacement → référence
     */
    public function of(array $playerIds): array;
}
