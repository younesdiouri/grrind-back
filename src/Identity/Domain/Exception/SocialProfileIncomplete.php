<?php

declare(strict_types=1);

namespace App\Identity\Domain\Exception;

use App\Identity\Domain\SocialProvider;
use App\Shared\Domain\Exception\RuleViolationError;

/**
 * Authentification réussie, mais sans adresse e-mail. Le cas se produit chez Apple, qui
 * ne renvoie l'adresse qu'à la toute première autorisation : la sortie est côté
 * utilisateur — retirer l'app dans les réglages Apple, puis recommencer.
 */
final class SocialProfileIncomplete extends RuleViolationError
{
    public function __construct(SocialProvider $provider)
    {
        parent::__construct(
            \sprintf('%s n\'a pas communiqué d\'adresse e-mail, indispensable pour créer un compte.', ucfirst($provider->value)),
            ['provider' => $provider->value],
        );
    }

    public function type(): string
    {
        return 'social-profile-incomplete';
    }
}
