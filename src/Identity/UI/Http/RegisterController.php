<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Identity\Application\RegisterUser;
use App\Identity\Application\RegisterUserHandler;
use App\Identity\UI\Http\Request\RegisterRequest;
use App\Identity\UI\Http\Response\UserResource;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final readonly class RegisterController
{
    public function __construct(private RegisterUserHandler $register)
    {
    }

    #[Route('/api/auth/register', name: 'identity_register', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] RegisterRequest $request): JsonResponse
    {
        $user = ($this->register)(new RegisterUser(
            $request->email,
            $request->password,
            $request->displayName,
            $request->timezone,
        ));

        return new JsonResponse(UserResource::from($user)->toArray(), Response::HTTP_CREATED);
    }
}
