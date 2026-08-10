<?php

declare(strict_types=1);

namespace App\Identity\UI\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de `POST /api/auth/social/{provider}`.
 *
 * Le client iOS a déjà mené l'écran d'autorisation ; il nous transmet le code à
 * échanger. Le secret client ne quitte jamais le serveur.
 */
final readonly class SocialSignInRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 2048)]
        public string $code = '',
        // Doit être exactement celui présenté au fournisseur à l'étape précédente
        // (un schéma d'URL personnalisé, côté iOS). Les deux le recomparent.
        #[Assert\NotBlank]
        #[Assert\Length(max: 2048)]
        public string $redirectUri = '',
        // PKCE : facultatif côté protocole, recommandé pour un client public.
        #[Assert\Length(min: 43, max: 128)]
        public ?string $codeVerifier = null,
        // Aucun fournisseur ne donne le fuseau, et le streak en dépend.
        #[Assert\NotBlank]
        #[Assert\Timezone]
        public string $timezone = 'UTC',
    ) {
    }
}
