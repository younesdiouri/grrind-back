<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Identity\Application\RegisterDevice;
use App\Identity\Application\RegisterDeviceHandler;
use App\Identity\Domain\User;
use App\Identity\UI\Http\Request\RegisterDeviceRequest;
use App\Identity\UI\Http\Response\DeviceResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * `#[CurrentUser]` et rien d'autre : enregistrer son propre téléphone est une donnée du
 * joueur courant, l'invariant de CLAUDE.md tient ici comme sur `/api/me` — aucun
 * identifiant de compte dans l'URL ni dans le corps.
 *
 * `200` et non `201` : la route est un upsert par construction (le client réenregistre son
 * jeton à chaque démarrage, recommandation Apple comme Expo), donc rien ne distingue pour
 * l'appelant un premier enregistrement d'un réenregistrement — les deux rendent le même
 * appareil à jour.
 */
final readonly class DeviceController
{
    public function __construct(private RegisterDeviceHandler $register)
    {
    }

    #[Route('/api/devices', name: 'identity_device_register', methods: ['POST'])]
    #[OA\Tag(name: 'Appareils')]
    #[OA\Response(
        response: 200,
        description: "L'appareil est enregistré — ou son propriétaire mis à jour si le jeton appartenait à un autre compte.",
        content: new OA\JsonContent(ref: '#/components/schemas/Device'),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntity')]
    public function __invoke(#[CurrentUser] User $user, #[MapRequestPayload] RegisterDeviceRequest $request): JsonResponse
    {
        // Les valeurs non nulles sont garanties par la validation, qui a déjà rendu un 422
        // sinon — même idiome que ImportWorkoutsController::candidate().
        \assert(null !== $request->platform && null !== $request->environment);

        $device = ($this->register)($user, new RegisterDevice($request->pushToken, $request->platform, $request->environment));

        return new JsonResponse(DeviceResource::from($device)->toArray());
    }
}
