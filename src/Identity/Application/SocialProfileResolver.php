<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Exception\SocialSignInRejected;
use App\Identity\Domain\SocialProvider;

/**
 * Échange un code d'autorisation contre un profil normalisé.
 *
 * Le seul port du module, et il se justifie deux fois : aucune bibliothèque n'abstrait
 * « code → profil » (`AbstractProvider` s'arrête au jeton), et aucun test ne peut
 * appeler Google. Le stub est en `when@test` dans config/services.yaml.
 */
interface SocialProfileResolver
{
    /**
     * @param string      $code         code d'autorisation obtenu par le client natif
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
