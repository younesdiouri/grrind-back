<?php

declare(strict_types=1);

namespace App\Community\Domain\Exception;

use App\Shared\Domain\Exception\ForbiddenError;

final class AlamManualDisabled extends ForbiddenError
{
    public function __construct()
    {
        parent::__construct('Le lancement manuel est désactivé.');
    }

    public function type(): string
    {
        return 'alam-manual-disabled';
    }
}
