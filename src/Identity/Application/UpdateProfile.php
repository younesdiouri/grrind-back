<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * `null` signifie « ne touche pas à ce champ » — un PATCH ne remet pas à zéro ce
 * qu'il n'envoie pas. Même règle pour `notificationPreferences`, à la clé près : une
 * carte vide ne touche à rien, et une catégorie absente de la carte envoyée reste ce
 * qu'elle était.
 */
final readonly class UpdateProfile
{
    /**
     * @param array<string, bool> $notificationPreferences clé = {@see \App\Shared\Domain\NotificationCategory}::value
     */
    public function __construct(
        public ?string $displayName = null,
        public ?string $timezone = null,
        public ?string $locale = null,
        public array $notificationPreferences = [],
    ) {
    }
}
