<?php

declare(strict_types=1);

namespace App\Community\Domain;

enum AlamAction: string
{
    case Arrival = 'ARRIVAL';
    case Effort = 'EFFORT';
    case Victory = 'VICTORY';
    case Defeat = 'DEFEAT';
    case Drop = 'DROP';
}
