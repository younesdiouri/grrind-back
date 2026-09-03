<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Identity\Application\AuthenticatedUser;
use App\Identity\Application\IssueTokens;
use App\Identity\Application\RegisterUser;
use App\Identity\Application\RegisterUserHandler;
use App\Identity\Domain\Locale;
use App\Identity\UI\Http\Request\RegisterRequest;
use App\Identity\UI\Http\Response\AuthResource;
use App\Shared\Application\PlayerTitles;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final readonly class RegisterController
{
    public function __construct(
        private RegisterUserHandler $register,
        private IssueTokens $issueTokens,
        private PlayerTitles $titles,
    ) {
    }

    #[Route('/api/auth/register', name: 'identity_register', methods: ['POST'])]
    #[OA\Tag(name: 'Authentification')]
    #[Security(name: null)]
    #[OA\Response(
        response: 201,
        description: 'Le compte est ouvert **et la session aussi** : forcer un login juste après ajouterait un aller-retour sur l\'étape la plus fragile du tunnel.',
        content: new OA\JsonContent(ref: '#/components/schemas/AuthSession'),
    )]
    #[OA\Response(
        response: 409,
        description: 'Adresse déjà prise (`email-already-used`).',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/ProblemDetails'),
        ),
    )]
    #[OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntity')]
    public function __invoke(Request $httpRequest, #[MapRequestPayload] RegisterRequest $request): JsonResponse
    {
        $user = ($this->register)(new RegisterUser(
            $request->email,
            $request->password,
            $request->displayName,
            $request->timezone,
            self::localeOf($request->locale, $httpRequest),
        ));

        // L'inscription ouvre directement la session : forcer un login juste après
        // ajouterait un aller-retour sur l'étape la plus fragile du tunnel.
        $authenticated = new AuthenticatedUser($user, ($this->issueTokens)($user));

        return new JsonResponse(AuthResource::from($authenticated, $this->titles->of($user->id()))->toArray(), Response::HTTP_CREATED);
    }

    private static function localeOf(?string $requested, Request $request): Locale
    {
        return Locale::tryFrom($requested ?? '')
            ?? Locale::tryFrom($request->getPreferredLanguage(Locale::values()) ?? '')
            ?? Locale::English;
    }
}
