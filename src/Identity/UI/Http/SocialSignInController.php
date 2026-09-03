<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Identity\Application\SignInWithProvider;
use App\Identity\Application\SignInWithProviderHandler;
use App\Identity\Domain\Locale;
use App\Identity\Domain\SocialProvider;
use App\Identity\UI\Http\Request\SocialSignInRequest;
use App\Identity\UI\Http\Response\AuthResource;
use App\Shared\Application\PlayerTitles;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Une seule route pour se connecter *et* s'inscrire : côté client c'est un seul bouton,
 * et la réponse est le même `AuthResource` que le login classique.
 *
 * `SocialProvider` est résolu depuis l'URL par le résolveur d'enum de Symfony : un
 * fournisseur inconnu donne un 404 avant d'atteindre le contrôleur.
 */
final readonly class SocialSignInController
{
    public function __construct(
        private SignInWithProviderHandler $signIn,
        private PlayerTitles $titles,
    ) {
    }

    #[Route(
        '/api/auth/social/{provider}',
        name: 'identity_social_sign_in',
        requirements: ['provider' => 'google|apple'],
        methods: ['POST'],
    )]
    #[OA\Tag(name: 'Authentification')]
    #[Security(name: null)]
    #[OA\Response(
        response: 200,
        description: 'Compte rattaché ou créé. Un compte né ici n\'a pas de mot de passe et ne peut pas passer par `/api/auth/login`.',
        content: new OA\JsonContent(ref: '#/components/schemas/AuthSession'),
    )]
    #[OA\Response(
        response: 401,
        description: 'Le fournisseur a refusé le code (`social-sign-in-rejected`).',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/ProblemDetails'),
        ),
    )]
    #[OA\Response(
        response: 409,
        description: 'L\'adresse appartient à un compte existant que le fournisseur ne certifie pas (`email-belongs-to-another-account`) — sans certification, rattacher serait une prise de contrôle en une requête. Ou profil incomplet (`social-profile-incomplete`).',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/ProblemDetails'),
        ),
    )]
    #[OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntity')]
    public function __invoke(SocialProvider $provider, Request $httpRequest, #[MapRequestPayload] SocialSignInRequest $request): JsonResponse
    {
        $authenticated = ($this->signIn)(new SignInWithProvider(
            $provider,
            $request->code,
            $request->redirectUri,
            $request->codeVerifier,
            $request->timezone,
            self::localeOf($request->locale, $httpRequest),
        ));

        return new JsonResponse(AuthResource::from($authenticated, $this->titles->of($authenticated->user->id()))->toArray());
    }

    private static function localeOf(?string $requested, Request $request): Locale
    {
        return Locale::tryFrom($requested ?? '')
            ?? Locale::tryFrom($request->getPreferredLanguage(Locale::values()) ?? '')
            ?? Locale::English;
    }
}
