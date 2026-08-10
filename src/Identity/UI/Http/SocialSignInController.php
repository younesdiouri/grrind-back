<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Identity\Application\SignInWithProvider;
use App\Identity\Application\SignInWithProviderHandler;
use App\Identity\Domain\SocialProvider;
use App\Identity\UI\Http\Request\SocialSignInRequest;
use App\Identity\UI\Http\Response\AuthResource;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Connexion par Google ou Apple. Une seule route pour se connecter *et* s'inscrire :
 * côté client c'est un seul bouton, et lui demander de savoir à l'avance si le
 * compte existe n'aurait aucun sens.
 *
 * La réponse est le même `AuthResource` que le login et l'inscription classiques —
 * un seul chemin de traitement côté iOS.
 *
 * `SocialProvider` est résolu depuis l'URL par le résolveur d'enum de Symfony : un
 * fournisseur inconnu donne un 404 avant d'atteindre le contrôleur.
 */
final readonly class SocialSignInController
{
    public function __construct(private SignInWithProviderHandler $signIn)
    {
    }

    #[Route(
        '/api/auth/social/{provider}',
        name: 'identity_social_sign_in',
        requirements: ['provider' => 'google|apple'],
        methods: ['POST'],
    )]
    public function __invoke(SocialProvider $provider, #[MapRequestPayload] SocialSignInRequest $request): JsonResponse
    {
        $authenticated = ($this->signIn)(new SignInWithProvider(
            $provider,
            $request->code,
            $request->redirectUri,
            $request->codeVerifier,
            $request->timezone,
        ));

        return new JsonResponse(AuthResource::from($authenticated)->toArray());
    }
}
