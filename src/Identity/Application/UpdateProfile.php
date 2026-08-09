<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * `null` signifie « ne touche pas à ce champ » — un PATCH ne remet pas à zéro ce
 * qu'il n'envoie pas.
 */
final readonly class UpdateProfile
{
    public function __construct(
        public ?string $displayName = null,
        public ?string $timezone = null,
    ) {
    }
}
