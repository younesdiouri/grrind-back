<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine\Type;

use App\Shared\Domain\Timezone;

final class TimezoneType extends StringValueType
{
    public const string NAME = 'timezone';

    protected function valueClass(): string
    {
        return Timezone::class;
    }
}
