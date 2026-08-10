<?php

declare(strict_types=1);

namespace App\Identity\Domain\Exception;

use App\Identity\Domain\SocialProvider;
use App\Shared\Domain\Exception\ConflictError;

/**
 * Un compte existe déjà sur cette adresse, mais le fournisseur ne certifie pas que
 * la personne en face la possède.
 *
 * Rattacher quand même serait une prise de contrôle de compte en une requête : il
 * suffirait de créer chez le fournisseur un compte portant l'adresse de la victime.
 * On refuse, et on laisse le vrai propriétaire se connecter par mot de passe.
 */
final class EmailBelongsToAnotherAccount extends ConflictError
{
    public function __construct(SocialProvider $provider)
    {
        parent::__construct(
            'Un compte existe déjà sur cette adresse e-mail. Connecte-toi par mot de passe, puis relie ce fournisseur depuis ton profil.',
            ['provider' => $provider->value],
        );
    }

    public function type(): string
    {
        return 'email-belongs-to-another-account';
    }
}
