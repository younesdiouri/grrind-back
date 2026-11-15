<?php

declare(strict_types=1);

namespace App\Community\UI\Http\Request;

use App\Community\Domain\Guild;
use App\Community\Domain\GuildEmblem;
use OpenApi\Attributes as OA;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Champs absents ou null = inchangés ; un emblème fourni remplace ses trois choix.
 * Le Serializer impose les enums et les trois arguments, le Validator leur contraste.
 *
 * @see https://symfony.com/doc/current/controller.html#mapping-request-payload
 */
final readonly class UpdateGuildRequest
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
        #[Assert\Length(max: Guild::NAME_MAX_LENGTH, normalizer: 'trim')]
        public ?string $name = null,
        #[OA\Property(ref: '#/components/schemas/GuildEmblem', nullable: true)]
        #[Assert\Valid]
        public ?GuildEmblem $emblem = null,
    ) {
    }

    #[Ignore]
    #[Assert\IsTrue(message: 'Fournissez un nom ou un emblème.')]
    public function hasChange(): bool
    {
        return null !== $this->name || null !== $this->emblem;
    }
}
