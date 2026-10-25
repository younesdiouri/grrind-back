<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Les emplacements sortis du vocabulaire, que des snapshots figés portent encore (#321).
 *
 * Un snapshot publié est immuable : une run Ālam ou un combat ouvert avant le retrait du slot
 * mains garde `IRON_GAUNTLETS` sur `HANDS`. Le relire ne doit pas faire tomber le lancement :
 * l'objet n'existe plus, il n'entre donc ni au catalogue ni au tirage. Partagé parce que
 * `Rewards` lit le catalogue et `Community` tire l'équipement d'Ālam sans se connaître.
 * Un slot inconnu absent de cette liste reste une faute, et se refuse.
 */
final class RetiredEquipmentSlots
{
    /** `HANDS` disparaît avec les équipements sans 3D (grrind-back `41b2027`). */
    public const array SLOTS = ['HANDS'];

    /** @param array{slot?: string, ...} $item */
    public static function carries(array $item): bool
    {
        return \in_array($item['slot'] ?? null, self::SLOTS, true);
    }
}
