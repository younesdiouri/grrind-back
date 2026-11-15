<?php

declare(strict_types=1);

namespace App\Community\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Trois choix atomiques, sans image ni couches. Dans la palette GRRIND retenue,
 * seules les paires NIGHT/autre atteignent un contraste de 3:1 ; l'inversion reste libre.
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

    #[Assert\IsTrue(message: 'Une des deux couleurs doit être NIGHT et l’autre une couleur claire de la palette.')]
    public function isContrasted(): bool
    {
        return (GuildEmblemColor::Night === $this->backgroundColor) !== (GuildEmblemColor::Night === $this->symbolColor);
    }
}
