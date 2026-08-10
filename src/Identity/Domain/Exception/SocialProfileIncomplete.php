<?php

declare(strict_types=1);

namespace App\Identity\Domain\Exception;

use App\Identity\Domain\SocialProvider;
use App\Shared\Domain\Exception\RuleViolationError;

/**
 * Le fournisseur a bien authentifié quelqu'un, mais sans adresse e-mail — et un
 * compte GRRIND en exige une.
 *
 * Le cas se produit chez Apple quand l'utilisateur a révoqué le partage de son
 * relais privé : Apple ne renvoie alors l'adresse qu'à la toute première
 * autorisation. La sortie est côté utilisateur — retirer l'app dans les réglages
 * Apple puis recommencer — pas côté serveur.
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
