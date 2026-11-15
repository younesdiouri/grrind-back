<?php

declare(strict_types=1);

namespace App\Community\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Trois choix atomiques, sans image ni couches. Les deux couleurs sont indépendantes :
 * toutes les paires de la palette sont conservées telles quelles, même identiques (#381).
 *
 * @see https://www.doctrine-project.org/projects/doctrine-orm/en/current/tutorials/embeddables.html
 */
#[ORM\Embeddable]
final readonly class GuildEmblem
{
    public function __construct(
        #[ORM\Column(length: 16, enumType: GuildEmblemSymbol::class, options: ['default' => 'DUMBBELL'])]
        public GuildEmblemSymbol $symbol,
        #[ORM\Column(length: 16, enumType: GuildEmblemColor::class, options: ['default' => 'NIGHT'])]
        public GuildEmblemColor $backgroundColor,
        #[ORM\Column(length: 16, enumType: GuildEmblemColor::class, options: ['default' => 'CYAN'])]
        public GuildEmblemColor $symbolColor,
    ) {
    }
}
