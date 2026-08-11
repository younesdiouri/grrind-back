<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Identity\Application\UpdateProfile;
use App\Identity\Application\UpdateProfileHandler;
use App\Identity\Domain\User;
use App\Identity\UI\Http\Request\UpdateProfileRequest;
use App\Identity\UI\Http\Response\UserResource;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Le `User` vient du jeton : aucune route ne prend d'identifiant de compte en paramètre,
 * donc aucune ne peut être détournée pour lire le profil d'un autre.
 *
 * Le firewall étant stateless, le compte est relu à chaque requête — un profil modifié
 * entre deux appels est à jour.
 */
final readonly class MeController
{
    public function __construct(private UpdateProfileHandler $updateProfile)
    {
    }

    #[Route('/api/me', name: 'identity_me', methods: ['GET'])]
    public function show(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(UserResource::from($user)->toArray());
    }

    #[Route('/api/me', name: 'identity_me_update', methods: ['PATCH'])]
    public function update(#[CurrentUser] User $user, #[MapRequestPayload] UpdateProfileRequest $request): JsonResponse
    {
        $updated = ($this->updateProfile)($user, new UpdateProfile($request->displayName, $request->timezone));

        return new JsonResponse(UserResource::from($updated)->toArray());
    }
}
