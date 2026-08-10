<?php

declare(strict_types=1);

namespace App\Identity\UI\Http\Request;

use App\Identity\Domain\User;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Contrat d'entrée de `POST /api/auth/register`. Les contraintes ici sont celles
 * du *format* ; les règles métier restent dans le domaine. Les deux se recoupent
 * volontairement : le DTO produit un 422 lisible avec le champ fautif, le domaine
 * garantit qu'aucun chemin ne contourne l'invariant.
 */
final readonly class RegisterRequest
{
    public function __construct(
        // `normalizer: trim` parce qu'un clavier iOS ajoute une espace finale sans
        // rien demander : refuser l'adresse pour ça serait un bug de notre côté.
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Email(normalizer: 'trim')]
        #[Assert\Length(max: User::EMAIL_MAX_LENGTH, normalizer: 'trim')]
        public string $email = '',
        // La longueur est le facteur qui compte vraiment ; le plafond n'est là que
        // pour qu'un mot de passe d'un mégaoctet ne parte pas au hachage.
        #[Assert\NotBlank]
        #[Assert\Length(min: 12, max: 4096)]
        public string $password = '',
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: User::DISPLAY_NAME_MAX_LENGTH, normalizer: 'trim')]
        public string $displayName = '',
        // Le client iOS envoie le fuseau de l'appareil. Il conditionne le calcul du
        // streak, donc on ne le devine pas côté serveur.
        #[Assert\NotBlank]
        #[Assert\Timezone]
        public string $timezone = 'UTC',
    ) {
    }
}
