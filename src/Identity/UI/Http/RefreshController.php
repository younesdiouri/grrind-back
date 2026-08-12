<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Identity\Application\RefreshSession;
use App\Identity\Application\RefreshSessionHandler;
use App\Identity\UI\Http\Request\RefreshTokenRequest;
use App\Identity\UI\Http\Response\AuthResource;
use App\Shared\Application\PlayerTitles;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final readonly class RefreshController
{
    public function __construct(
        private RefreshSessionHandler $refresh,
        private PlayerTitles $titles,
    ) {
    }

    #[Route('/api/auth/refresh', name: 'identity_refresh', methods: ['POST'])]
    #[OA\Tag(name: 'Authentification')]
    #[Security(name: null)]
    #[OA\Response(
        response: 200,
        description: 'Une paire neuve. **Le jeton présenté est consommé** : il est à usage unique et rotatif.',
        content: new OA\JsonContent(ref: '#/components/schemas/AuthSession'),
    )]
    #[OA\Response(
        response: 401,
        description: 'Jeton inconnu, expiré, **ou déjà consommé** (`invalid-refresh-token`). Un rejeu révoque toute la famille : on ne peut pas distinguer le voleur du vrai client qui a été doublé, donc on coupe.',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/ProblemDetails'),
        ),
    )]
    #[OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntity')]
    public function __invoke(#[MapRequestPayload] RefreshTokenRequest $request): JsonResponse
    {
        $authenticated = ($this->refresh)(new RefreshSession($request->refreshToken));

        return new JsonResponse(AuthResource::from($authenticated, $this->titles->of($authenticated->user->id()))->toArray());
    }
}
