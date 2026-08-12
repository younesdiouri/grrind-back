<?php

declare(strict_types=1);

namespace App\Identity\UI\Http\Request;

use App\Identity\Domain\User;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Contrat d'entrée de `POST /api/auth/register` : les contraintes portent sur le
 * *format*, les règles métier restent dans le domaine. Le recoupement est voulu — le DTO
 * produit un 422 nommant le champ, le domaine garantit qu'aucun chemin ne le contourne.
 */
final readonly class RegisterRequest
{
    public function __construct(
        // Un clavier iOS ajoute une espace finale sans rien demander.
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Email(normalizer: 'trim')]
        #[Assert\Length(max: User::EMAIL_MAX_LENGTH, normalizer: 'trim')]
        public string $email = '',
        // Le plafond n'est là que pour qu'un mot de passe d'un mégaoctet ne parte pas
        // au hachage.
        #[Assert\NotBlank]
        #[Assert\Length(min: 12, max: 4096)]
        public string $password = '',
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: User::DISPLAY_NAME_MAX_LENGTH, normalizer: 'trim')]
        public string $displayName = '',
        // Le client envoie le fuseau de l'appareil. Il conditionne le calcul du
        // streak, donc on ne le devine pas côté serveur.
        #[Assert\NotBlank]
        #[Assert\Timezone]
        public string $timezone = 'UTC',
    ) {
    }
}
