<?php

declare(strict_types=1);

namespace App\Community\Domain;

enum GuildEmblemColor: string
{
    case Night = 'NIGHT';
    case Chalk = 'CHALK';
    case Cyan = 'CYAN';
    case Mint = 'MINT';
    case Amethyst = 'AMETHYST';
    case Glacier = 'GLACIER';
    case Blossom = 'BLOSSOM';
    case Copper = 'COPPER';
}
