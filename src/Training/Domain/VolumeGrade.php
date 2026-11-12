<?php

declare(strict_types=1);

namespace App\Training\Domain;

enum VolumeGrade: string
{
    case A = 'A';
    case B = 'B';
    case C = 'C';
    case D = 'D';
    case E = 'E';
}
