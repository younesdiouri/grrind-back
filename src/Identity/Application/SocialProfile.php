<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\SocialProvider;

/**
 * Ce qu'un fournisseur d'identité nous apprend sur la personne, une fois ramené à
 * une forme commune. Google et Apple ne décrivent pas un utilisateur de la même
 * façon ; au-delà de cette frontière, plus rien ne s'en aperçoit.
 */
final readonly class SocialProfile
{
    public function __construct(
        public SocialProvider $provider,
        /** Le `sub` du fournisseur : opaque, stable, la seule clé de liaison fiable. */
        public string $subject,
        public ?string $email,
        /**
         * Le fournisseur certifie-t-il que la personne possède cette adresse ?
         * C'est ce booléen, et lui seul, qui autorise à rattacher la connexion à un
         * compte préexistant.
         */
        public bool $emailVerified,
        public ?string $displayName,
    ) {
    }
}
