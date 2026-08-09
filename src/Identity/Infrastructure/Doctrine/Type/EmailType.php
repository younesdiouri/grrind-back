<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine\Type;

use App\Identity\Domain\Email;
use App\Shared\Infrastructure\Doctrine\Type\StringValueType;

final class EmailType extends StringValueType
{
    public const string NAME = 'email';

    protected function valueClass(): string
    {
        return Email::class;
    }
}
