<?php

declare(strict_types=1);

namespace App\Training\Domain;

enum StatsPeriod: string
{
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';
}
