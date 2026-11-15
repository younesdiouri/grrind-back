<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\Guild;
use Symfony\Component\Uid\Uuid;

/**
 * L'acteur vient du jeton ; son droit d'exclure est revalidé sous verrou après le voter HTTP.
 */
final readonly class ExcludeMember
{
    public function __construct(
        public Guild $guild,
        public Uuid $actorId,
        public Uuid $excludedId,
    ) {
    }
}
