<?php

declare(strict_types=1);

namespace App\Community\Domain;

enum AlamStatus: string
{
    case Collecting = 'COLLECTING';
    case Resolved = 'RESOLVED';
}
