<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Identity\Application\LogIn;
use App\Identity\Application\LogInHandler;
use App\Identity\UI\Http\Request\LoginRequest;
use App\Identity\UI\Http\Response\AuthResource;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final readonly class LoginController
{
    public function __construct(private LogInHandler $logIn)
    {
    }

    #[Route('/api/auth/login', name: 'identity_login', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] LoginRequest $request): JsonResponse
    {
        $authenticated = ($this->logIn)(new LogIn($request->email, $request->password));

        return new JsonResponse(AuthResource::from($authenticated)->toArray());
    }
}
