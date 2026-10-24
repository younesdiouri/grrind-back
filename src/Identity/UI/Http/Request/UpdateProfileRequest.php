<?php

declare(strict_types=1);

namespace App\Identity\UI\Http\Request;

use App\Identity\Domain\Locale;
use App\Identity\Domain\User;
use App\Shared\Domain\Avatar;
use OpenApi\Attributes as OA;
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
     * @param array<string, mixed>|null           $avatar                  remplacement complet, null = inchangé
     */
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
        #[Assert\Length(max: User::DISPLAY_NAME_MAX_LENGTH, normalizer: 'trim')]
        public ?string $displayName = null,
        #[Assert\Timezone]
        public ?string $timezone = null,
        #[Assert\Choice(callback: [Locale::class, 'values'])]
        public ?string $locale = null,
        // `Valid` fait descendre la validation dans chaque élément — même pattern que
        // `ImportWorkoutsRequest::$workouts` ; pas de `Count` ici, une liste vide est le
        // cas nominal d'un PATCH qui ne touche pas aux préférences.
        #[Assert\Valid]
        public array $notificationPreferences = [],
        // Le handler vérifie la disponibilité dans le catalogue graphique administrable.
        #[Assert\Regex('/^[A-Z][A-Z0-9_]{0,63}$/')]
        public ?string $appearance = null,
        #[OA\Property(ref: '#/components/schemas/Avatar', nullable: true, description: 'Remplacement complet ; absent ou null = inchangé. Aucun équipement accepté.')]
        #[Assert\Sequentially([
            new Assert\Collection(fields: [
                'version' => new Assert\IdenticalTo(Avatar::VERSION),
                'body' => [new Assert\NotNull(), new Assert\Choice(choices: Avatar::OPTIONS['body'])],
                'faceStyle' => [new Assert\NotNull(), new Assert\Choice(choices: Avatar::OPTIONS['faceStyle'])],
                'noseStyle' => [new Assert\NotNull(), new Assert\Choice(choices: Avatar::OPTIONS['noseStyle'])],
                'hairStyle' => [new Assert\NotNull(), new Assert\Choice(choices: Avatar::OPTIONS['hairStyle'])],
                'skinTone' => [new Assert\NotNull(), new Assert\Choice(choices: Avatar::OPTIONS['skinTone'])],
                'eyeColor' => [new Assert\NotNull(), new Assert\Choice(choices: Avatar::OPTIONS['eyeColor'])],
                'hairColor' => [new Assert\NotNull(), new Assert\Choice(choices: Avatar::OPTIONS['hairColor'])],
            ]),
            new Assert\Expression("value == null or value['body'] == 'male' or (value['faceStyle'] == 'original' and value['noseStyle'] == 'original')", message: 'Le visage carré et le nez large sont disponibles uniquement sur le corps masculin.'),
        ])]
        public ?array $avatar = null,
    ) {
    }
}
