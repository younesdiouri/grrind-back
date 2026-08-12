<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\OAuth;

use App\Identity\Application\SocialProfile;
use App\Identity\Application\SocialProfileResolver;
use App\Identity\Domain\Exception\SocialSignInRejected;
use App\Identity\Domain\SocialProvider;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\AppleResourceOwner;
use League\OAuth2\Client\Provider\GoogleUser;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use League\OAuth2\Client\Token\AccessToken;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Throwable;

/**
 * L'échange réel, par league/oauth2-client.
 *
 * Le flux est celui d'un client natif : l'app ouvre elle-même l'écran
 * d'autorisation, récupère le code et nous l'envoie. Le serveur seul détient le secret
 * client — le code ne vaut rien sans lui.
 *
 * Les providers sont câblés à la main dans config/services.yaml (KnpUOAuth2ClientBundle
 * écarté : tout ce qu'il apporte suppose un navigateur) et arrivent par un service
 * locator, donc instanciés à la demande — le constructeur d'Apple refuse une
 * configuration vide, et un poste sans clé Apple doit pouvoir servir Google.
 */
final readonly class LeagueSocialProfileResolver implements SocialProfileResolver
{
    /**
     * @param ContainerInterface $providers indexé par la valeur de SocialProvider
     */
    public function __construct(
        #[AutowireLocator([
            'google' => new Autowire(service: 'oauth2.provider.google'),
            'apple' => new Autowire(service: 'oauth2.provider.apple'),
        ])]
        private ContainerInterface $providers,
        private LoggerInterface $logger,
    ) {
    }

    public function resolve(
        SocialProvider $provider,
        string $code,
        string $redirectUri,
        ?string $codeVerifier = null,
    ): SocialProfile {
        // Le redirect_uri doit être identique au bit près à celui de l'étape
        // précédente : il vient donc du client, pas de notre configuration.
        $options = ['code' => $code, 'redirect_uri' => $redirectUri];

        if (null !== $codeVerifier) {
            $options['code_verifier'] = $codeVerifier;
        }

        $oauth = $this->providers->get($provider->value);

        if (!$oauth instanceof AbstractProvider) {
            throw new SocialSignInRejected();
        }

        try {
            $token = $oauth->getAccessToken('authorization_code', $options);

            // getAccessToken() promet l'interface, getResourceOwner() exige la classe
            // concrète : incohérence de league/oauth2-client, pas la nôtre.
            if (!$token instanceof AccessToken) {
                throw new SocialSignInRejected();
            }

            $owner = $oauth->getResourceOwner($token);
        } catch (Throwable $e) {
            // Code expiré, déjà échangé, émis pour une autre app, PKCE qui ne colle
            // pas : même conduite à tenir côté client. Le détail va aux logs.
            $this->logger->warning('Échange OAuth refusé par {provider} : {message}', [
                'provider' => $provider->value,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            throw new SocialSignInRejected();
        }

        return match ($provider) {
            SocialProvider::Google => self::fromGoogle($owner),
            SocialProvider::Apple => self::fromApple($owner),
        };
    }

    /**
     * @throws SocialSignInRejected
     */
    private static function fromGoogle(ResourceOwnerInterface $owner): SocialProfile
    {
        if (!$owner instanceof GoogleUser) {
            throw new SocialSignInRejected();
        }

        // getId() est typé mixed : le `sub` OIDC est une chaîne, mais rien dans la
        // signature ne le garantit.
        $id = $owner->getId();

        if (!\is_string($id) || '' === $id) {
            throw new SocialSignInRejected();
        }

        return new SocialProfile(
            SocialProvider::Google,
            $id,
            $owner->getEmail(),
            // Absent vaut non vérifié : en cas de doute, on ne relie pas.
            true === $owner->getEmailVerified(),
            $owner->getName(),
        );
    }

    /**
     * @throws SocialSignInRejected
     */
    private static function fromApple(ResourceOwnerInterface $owner): SocialProfile
    {
        if (!$owner instanceof AppleResourceOwner) {
            throw new SocialSignInRejected();
        }

        $id = $owner->getId();

        if (null === $id || '' === $id) {
            throw new SocialSignInRejected();
        }

        $name = trim(\sprintf('%s %s', $owner->getFirstName() ?? '', $owner->getLastName() ?? ''));

        return new SocialProfile(
            SocialProvider::Apple,
            $id,
            $owner->getEmail(),
            // L'adresse vient de l'id_token, signature vérifiée. Une absence vaut
            // vérifié : Apple ne diffuse pas d'adresse qu'il n'a pas validée.
            self::appleSaysVerified($owner),
            '' === $name ? null : $name,
        );
    }

    private static function appleSaysVerified(AppleResourceOwner $owner): bool
    {
        $claim = $owner->getAttribute('email_verified');

        return match (true) {
            null === $claim => true,
            \is_bool($claim) => $claim,
            \is_string($claim) => 'true' === strtolower($claim),
            default => false,
        };
    }
}
