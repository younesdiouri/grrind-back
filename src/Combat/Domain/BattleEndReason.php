<?php

declare(strict_types=1);

namespace App\Combat\Domain;

enum BattleEndReason: string
{
    case Knockout = 'KO';
    case AttackLimit = 'ATTACK_LIMIT';
}
