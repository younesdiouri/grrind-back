<?php

declare(strict_types=1);

namespace App\Combat\Domain;

enum StatSource: string
{
    case Strength = 'strength';
    case Endurance = 'endurance';
    case Mobility = 'mobility';
    case Dexterity = 'dexterity';
    case Vitality = 'vitality';
}
