<?php

declare(strict_types=1);

namespace App\Community\Domain;

enum AlamMode: string
{
    case Weekly = 'WEEKLY';
    case Manual = 'MANUAL';
}
