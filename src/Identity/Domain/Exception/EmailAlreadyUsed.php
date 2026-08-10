<?php

declare(strict_types=1);

namespace App\Identity\Domain\Exception;

use App\Shared\Domain\Exception\ConflictError;

final class EmailAlreadyUsed extends ConflictError
{
    public function __construct(string $email)
    {
        parent::__construct(\sprintf('L\'adresse "%s" est déjà utilisée.', $email));
    }

    public function type(): string
    {
        return 'email-already-used';
    }
}
