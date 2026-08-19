<?php

declare(strict_types=1);

namespace App\Identity\UI\Http\Request;

use App\Shared\Domain\NotificationCategory;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un changement de préférence, une catégorie à la fois — jamais un remplacement complet
 * de la carte. Une catégorie absente de la liste envoyée reste ce qu'elle était, même
 * logique que `displayName`/`timezone` sur {@see UpdateProfileRequest} : seul ce qui est
 * explicitement envoyé change.
 */
final readonly class NotificationPreferenceRequest
{
    public function __construct(
        // Nullable avec un défaut nul plutôt que non nullable : sans ça, une valeur
        // absente ou hors énumération donne une erreur de dénormalisation opaque au lieu
        // d'un 422 qui nomme le champ. `NotNull` fait le reste — même pattern que
        // `ImportedWorkoutRequest::$source`.
        #[Assert\NotNull]
        public ?NotificationCategory $category = null,
        #[Assert\NotNull]
        public ?bool $enabled = null,
    ) {
    }
}
