<?php

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Port de rendu d'une image d'objet depuis sa clé historique.
 *
 * Combat ne connaît pas le catalogue Rewards : il a seulement besoin de compléter les faits
 * antérieurs au champ `imageUrl` sans réécrire leur JSON persistant.
 */
interface ItemImageUrlResolver
{
    public function imageUrlOf(string $key): string;
}
