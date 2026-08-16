<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\Guild;

/**
 * La guilde arrive **déjà chargée et déjà autorisée** — le resolver a rendu le 404,
 * `#[IsGranted]` le 403. Le handler n'a plus de décision d'accès à prendre, et c'est ce
 * qui garantit qu'il n'en prendra pas une deuxième, différente.
 */
final readonly class RenameGuild
{
    public function __construct(
        public Guild $guild,
        public string $name,
    ) {
    }
}
