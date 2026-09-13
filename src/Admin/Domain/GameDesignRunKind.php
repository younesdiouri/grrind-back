<?php

declare(strict_types=1);

namespace App\Admin\Domain;

enum GameDesignRunKind: string
{
    case Cohort = 'cohort';
    case Campaign = 'campaign';
    case Combat = 'combat';
    case Progression = 'progression';
}
