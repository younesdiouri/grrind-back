<?php

declare(strict_types=1);

namespace App\Admin\Domain;

enum GameDesignRunKind: string
{
    case Combat = 'combat';
    case Progression = 'progression';
}
