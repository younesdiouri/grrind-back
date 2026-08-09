<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Shared\UI\Http\ProblemDetails;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTExpiredEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTInvalidEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTNotFoundEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les échecs d'authentification ne traversent pas kernel.exception : l'authenticator
 * fabrique lui-même sa réponse. Sans ce branchement, l'API répondrait du
 * `{"code":401,"message":"..."}` maison de Lexik au milieu de problem+json partout
 * ailleurs.
 *
 * Lexik dispatche ces événements sous un nom, pas sous leur classe : écouter la
 * classe ne déclencherait jamais rien, silencieusement.
 *
 * On distingue « expiré » de « invalide » : le premier veut dire au client de
 * rafraîchir, le second de renvoyer l'utilisateur sur l'écran de connexion.
 */
final readonly class JwtFailureResponseSubscriber
{
    #[AsEventListener(event: Events::JWT_NOT_FOUND)]
    public function onTokenMissing(JWTNotFoundEvent $event): void
    {
        $event->setResponse(self::problem('access-token-missing', 'Aucun jeton d\'accès n\'a été fourni.'));
    }

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
