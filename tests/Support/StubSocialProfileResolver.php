<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Identity\Application\SocialProfile;
use App\Identity\Application\SocialProfileResolver;
use App\Identity\Domain\Exception\SocialSignInRejected;
use App\Identity\Domain\SocialProvider;
use JsonException;

/**
 * Remplace l'échange OAuth réel dans l'environnement de test : aucune suite ne peut
 * appeler Google ni Apple.
 *
 * Le code d'autorisation *est* le profil, encodé en base64url — plutôt qu'un état
 * programmé dans le conteneur. La raison est pratique : le KernelBrowser redémarre
 * le noyau entre deux requêtes, donc tout stub à état serait remis à zéro entre
 * l'appel qui le programme et celui qui le consomme.
 *
 * Un code que ce format ne reconnaît pas est refusé, exactement comme le ferait un
 * vrai fournisseur devant un code expiré.
 */
final readonly class StubSocialProfileResolver implements SocialProfileResolver
{
    /**
     * Fabrique le code d'autorisation qu'un test enverra à l'API.
     */
    public static function codeFor(
        string $subject,
        ?string $email = null,
        bool $emailVerified = true,
        ?string $displayName = null,
    ): string {
        $json = json_encode([
            'sub' => $subject,
            'email' => $email,
            'emailVerified' => $emailVerified,
            'displayName' => $displayName,
        ], \JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    public function resolve(
        SocialProvider $provider,
        string $code,
        string $redirectUri,
        ?string $codeVerifier = null,
    ): SocialProfile {
        $decoded = base64_decode(strtr($code, '-_', '+/'), true);

        if (false === $decoded) {
            throw new SocialSignInRejected();
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($decoded, true, 8, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SocialSignInRejected();
        }

        if (!\is_string($payload['sub'] ?? null) || '' === $payload['sub']) {
            throw new SocialSignInRejected();
        }

        return new SocialProfile(
            $provider,
            $payload['sub'],
            \is_string($payload['email'] ?? null) ? $payload['email'] : null,
            true === ($payload['emailVerified'] ?? null),
            \is_string($payload['displayName'] ?? null) ? $payload['displayName'] : null,
        );
    }
}
