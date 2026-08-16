<?php

declare(strict_types=1);

namespace App\Community\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Le joueur vient du jeton, jamais du corps : c'est ce qui empêche de fonder une guilde
 * au nom de quelqu'un d'autre.
 */
final readonly class FoundGuild
{
    public function __construct(
        public Uuid $founderId,
        public string $name,
    ) {
    }
}
