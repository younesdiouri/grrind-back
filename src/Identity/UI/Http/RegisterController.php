<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Identity\Application\AuthenticatedUser;
use App\Identity\Application\IssueTokens;
use App\Identity\Application\RegisterUser;
use App\Identity\Application\RegisterUserHandler;
use App\Identity\UI\Http\Request\RegisterRequest;
use App\Identity\UI\Http\Response\AuthResource;
use App\Shared\Application\PlayerTitles;
use Symfony\Component\HttpFoundation\JsonResponse;
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
    public function __invoke(#[MapRequestPayload] RegisterRequest $request): JsonResponse
    {
        $user = ($this->register)(new RegisterUser(
            $request->email,
            $request->password,
            $request->displayName,
            $request->timezone,
        ));

        // L'inscription ouvre directement la session : forcer un login juste après
        // ajouterait un aller-retour sur l'étape la plus fragile du tunnel.
        $authenticated = new AuthenticatedUser($user, ($this->issueTokens)($user));

        return new JsonResponse(AuthResource::from($authenticated, $this->titles->of($user->id()))->toArray(), Response::HTTP_CREATED);
    }
}
