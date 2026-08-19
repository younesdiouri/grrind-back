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
    /**
     * @param list<NotificationPreferenceRequest> $notificationPreferences liste vide =
     *                                                                     aucune préférence touchée ; voir {@see NotificationPreferenceRequest}
     */
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
        #[Assert\Length(max: User::DISPLAY_NAME_MAX_LENGTH, normalizer: 'trim')]
        public ?string $displayName = null,
        #[Assert\Timezone]
        public ?string $timezone = null,
        // `Valid` fait descendre la validation dans chaque élément — même pattern que
        // `ImportWorkoutsRequest::$workouts` ; pas de `Count` ici, une liste vide est le
        // cas nominal d'un PATCH qui ne touche pas aux préférences.
        #[Assert\Valid]
        public array $notificationPreferences = [],
    ) {
    }
}
