<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\SocialProvider;

/**
 * Ce qu'un fournisseur nous apprend, ramené à une forme commune : Google et Apple ne
 * décrivent pas un utilisateur pareil, et au-delà d'ici plus rien ne s'en aperçoit.
 */
final readonly class SocialProfile
{
    public function __construct(
        public SocialProvider $provider,
        /** Le `sub` du fournisseur : opaque, stable, la seule clé de liaison fiable. */
        public string $subject,
        public ?string $email,
        /**
         * Ce booléen, et lui seul, autorise à rattacher la connexion à un compte
         * préexistant.
         */
        public bool $emailVerified,
        public ?string $displayName,
    ) {
    }
}
