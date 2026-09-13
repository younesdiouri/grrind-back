<?php

declare(strict_types=1);

namespace App\Combat\Domain;

enum StatCombination: string
{
    case Single = 'single';
    case Sum = 'sum';
    case ArithmeticMean = 'arithmetic_mean';
    case GeometricMean = 'geometric_mean';
}
