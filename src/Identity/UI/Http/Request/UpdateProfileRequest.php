<?php

declare(strict_types=1);

namespace App\Identity\UI\Http\Request;

use App\Identity\Domain\User;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Champs absents = inchangés. Ni l'adresse ni le mot de passe ne passent par ici :
 * les deux touchent à l'authentification et méritent leurs propres routes, avec
 * confirmation.
 */
final readonly class UpdateProfileRequest
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
        #[Assert\Length(max: User::DISPLAY_NAME_MAX_LENGTH, normalizer: 'trim')]
        public ?string $displayName = null,
        #[Assert\Timezone]
        public ?string $timezone = null,
    ) {
    }
}
