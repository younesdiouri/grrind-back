<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Identity\Application\LogOut;
use App\Identity\Application\LogOutHandler;
use App\Identity\UI\Http\Request\RefreshTokenRequest;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final readonly class LogoutController
{
    public function __construct(private LogOutHandler $logOut)
    {
    }

    /**
     * Le jeton d'accès n'est pas requis : la preuve de possession du refresh token
     * suffit, et c'est justement quand le JWT vient d'expirer qu'on veut pouvoir
     * se déconnecter proprement.
     */
    #[Route('/api/auth/logout', name: 'identity_logout', methods: ['POST'])]
    #[OA\Tag(name: 'Authentification')]
    #[Security(name: null)]
    #[OA\Response(response: 204, description: 'La famille entière est révoquée — c\'est l\'appareil qui se déconnecte, pas seulement ce jeton. Rien à rendre.')]
    #[OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntity')]
    public function __invoke(#[MapRequestPayload] RefreshTokenRequest $request): JsonResponse
    {
        ($this->logOut)(new LogOut($request->refreshToken));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
