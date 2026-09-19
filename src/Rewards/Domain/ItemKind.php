<?php

declare(strict_types=1);

namespace App\Rewards\Domain;

/** Nature du catalogue : seuls les équipements portent un slot et des modificateurs. */
enum ItemKind: string
{
    case Equipment = 'EQUIPMENT';
    case Chest = 'CHEST';
    case Resource = 'RESOURCE';
}
