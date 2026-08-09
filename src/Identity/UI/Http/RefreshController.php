<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Identity\Application\RefreshSession;
use App\Identity\Application\RefreshSessionHandler;
use App\Identity\UI\Http\Request\RefreshTokenRequest;
use App\Identity\UI\Http\Response\AuthResource;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final readonly class RefreshController
{
    public function __construct(private RefreshSessionHandler $refresh)
    {
    }

    #[Route('/api/auth/refresh', name: 'identity_refresh', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] RefreshTokenRequest $request): JsonResponse
    {
        $authenticated = ($this->refresh)(new RefreshSession($request->refreshToken));

        return new JsonResponse(AuthResource::from($authenticated)->toArray());
    }
}
