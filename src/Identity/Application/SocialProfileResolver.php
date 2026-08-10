<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Exception\SocialSignInRejected;
use App\Identity\Domain\SocialProvider;

/**
 * Échange un code d'autorisation contre un profil normalisé.
 *
 * C'est l'un des rares ports que le projet garde, et pour deux raisons précises :
 * ni Symfony ni league/oauth2-client ne proposent d'abstraction « code → profil »
 * (`AbstractProvider` s'arrête au jeton, et chaque fournisseur décrit son
 * utilisateur à sa façon), et surtout un test ne peut pas appeler Google.
 * L'implémentation réelle vit dans Infrastructure\OAuth ; l'environnement de test
 * en substitue une autre — voir la section `when@test` de config/services.yaml.
 */
interface SocialProfileResolver
{
    /**
     * @param string      $code         code d'autorisation obtenu par le client iOS
     * @param string      $redirectUri  celui présenté au fournisseur à l'étape précédente ; il doit correspondre au bit près
     * @param string|null $codeVerifier PKCE, quand le client en a utilisé un
     *
     * @throws SocialSignInRejected
     */
    public function resolve(
        SocialProvider $provider,
        string $code,
        string $redirectUri,
        ?string $codeVerifier = null,
    ): SocialProfile;
}
