<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\AuthenticatedUser;
use App\Identity\Application\IssueTokens;
use App\Identity\Domain\User;
use App\Identity\UI\Http\Response\AuthResource;
use App\Shared\Application\PlayerTitles;
use App\Shared\UI\Http\ProblemDetails;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTExpiredEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTInvalidEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTNotFoundEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tout ce que l'API répond à propos de l'authentification passe par ici : les échecs ne
 * traversent pas `kernel.exception`, l'authenticator fabrique sa propre réponse, et sans
 * ce branchement le `{"code":401,…}` de Lexik sortirait au milieu du problem+json.
 *
 * Lexik dispatche sous un **nom**, pas sous la classe : écouter la classe ne
 * déclencherait rien, silencieusement. D'où les constantes `Events`.
 */
final readonly class AuthenticationResponseListener
{
    public function __construct(
        private IssueTokens $issueTokens,
        private PlayerTitles $titles,
    ) {
    }

    /**
     * Login réussi. Lexik a signé le JWT et s'apprêtait à répondre `{"token": …}` ;
     * on lui substitue le contrat GRRIND — profil et paire de jetons — pour que
     * login, inscription et rafraîchissement rendent tous la même forme et que le
     * client n'ait qu'un seul chemin de traitement.
     *
     * C'est aussi ici que naît la famille de refresh tokens : une par login, donc
     * une par appareil.
     */
    #[AsEventListener(event: Events::AUTHENTICATION_SUCCESS)]
    public function onLoginSucceeded(AuthenticationSuccessEvent $event): void
    {
        $user = $event->getUser();

        if (!$user instanceof User) {
            return;
        }

        $data = $event->getData();
        $accessToken = \is_string($data['token'] ?? null) ? $data['token'] : '';

        $tokens = $this->issueTokens->alongside($user, $accessToken);

        $event->setData(AuthResource::from(new AuthenticatedUser($user, $tokens), $this->titles->of($user->id()))->toArray());
    }

    /**
     * Adresse inconnue et mot de passe faux produisent le même corps, au même
     * statut : distinguer les deux ferait du login un oracle d'existence de comptes.
     * C'est le composant Security qui fond `UserNotFoundException` dans
     * `BadCredentialsException` — on n'a qu'à ne pas défaire son travail.
     */
    #[AsEventListener(event: Events::AUTHENTICATION_FAILURE)]
    public function onLoginFailed(AuthenticationFailureEvent $event): void
    {
        $event->setResponse(self::problem('invalid-credentials', 'Adresse e-mail ou mot de passe incorrect.'));
    }

    #[AsEventListener(event: Events::JWT_NOT_FOUND)]
    public function onTokenMissing(JWTNotFoundEvent $event): void
    {
        $event->setResponse(self::problem('access-token-missing', 'Aucun jeton d\'accès n\'a été fourni.'));
    }

    /**
     * « Expiré » et « invalide » restent distincts : le premier dit au client de
     * rafraîchir, le second de renvoyer l'utilisateur sur l'écran de connexion.
     */
    #[AsEventListener(event: Events::JWT_EXPIRED)]
    public function onTokenExpired(JWTExpiredEvent $event): void
    {
        $event->setResponse(self::problem('access-token-expired', 'Le jeton d\'accès a expiré.'));
    }

    #[AsEventListener(event: Events::JWT_INVALID)]
    public function onTokenInvalid(JWTInvalidEvent $event): void
    {
        $event->setResponse(self::problem('access-token-invalid', 'Le jeton d\'accès est invalide.'));
    }

    private static function problem(string $type, string $detail): JsonResponse
    {
        $problem = ProblemDetails::of($type, Response::HTTP_UNAUTHORIZED, $detail);

        return new JsonResponse(
            $problem->toArray(),
            $problem->status,
            ['Content-Type' => 'application/problem+json', 'WWW-Authenticate' => 'Bearer'],
        );
    }
}
