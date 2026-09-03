<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Identity\Application\UpdateProfile;
use App\Identity\Application\UpdateProfileHandler;
use App\Identity\Domain\User;
use App\Identity\UI\Http\Request\NotificationPreferenceRequest;
use App\Identity\UI\Http\Request\UpdateProfileRequest;
use App\Identity\UI\Http\Response\UserResource;
use App\Shared\Application\PlayerTitles;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Le `User` vient du jeton : **aucune route ne prend d'identifiant de compte pour servir les
 * données du joueur courant**, donc aucune de celles-là ne peut être détournée pour lire ce qui
 * appartient à un autre.
 *
 * La phrase s'arrêtait à « aucune route ne prend d'identifiant de compte » ; elle a été réécrite
 * au ticket 119, et pas contournée. Voir le profil d'un co-équipier, c'est lire les données d'un
 * autre compte : `GET /api/players/{id}` en prend un, vit dans `Community` — seul module capable
 * de répondre à « sommes-nous de la même guilde » — et est gardé par un voter, avec un 404 et
 * jamais un 403. Ce qui compte ici n'a pas bougé : cette route-ci n'accepte toujours aucun
 * identifiant, et c'est là qu'était le risque.
 *
 * Ce contrôleur reste par ailleurs le seul à servir l'adresse, le fuseau et le prochain titre
 * visé. Le profil public n'en expose aucun.
 *
 * Le firewall étant stateless, le compte est relu à chaque requête — un profil modifié
 * entre deux appels est à jour.
 */
final readonly class MeController
{
    public function __construct(
        private UpdateProfileHandler $updateProfile,
        /** Le port : `Identity` ne connaît ni le catalogue des titres ni la table des déblocages. */
        private PlayerTitles $titles,
    ) {
    }

    #[Route('/api/me', name: 'identity_me', methods: ['GET'])]
    #[OA\Tag(name: 'Profil')]
    #[OA\Response(
        response: 200,
        description: 'Le profil, titre porté et titre visé compris : l\'état du joueur en une requête à l\'ouverture de l\'app.',
        content: new OA\JsonContent(ref: '#/components/schemas/UserProfile'),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    public function show(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(UserResource::from($user, $this->titles->of($user->id()))->toArray());
    }

    #[Route('/api/me', name: 'identity_me_update', methods: ['PATCH'])]
    #[OA\Tag(name: 'Profil')]
    #[OA\Response(
        response: 200,
        description: 'Le profil mis à jour. Les champs absents ne sont pas touchés.',
        content: new OA\JsonContent(ref: '#/components/schemas/UserProfile'),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntity')]
    public function update(#[CurrentUser] User $user, #[MapRequestPayload] UpdateProfileRequest $request): JsonResponse
    {
        $updated = ($this->updateProfile)($user, new UpdateProfile(
            $request->displayName,
            $request->timezone,
            $request->locale,
            self::preferencesOf($request->notificationPreferences),
        ));

        return new JsonResponse(UserResource::from($updated, $this->titles->of($updated->id()))->toArray());
    }

    /**
     * Le DTO de requête décrit ce qu'un client HTTP a le droit d'envoyer, la commande ce
     * que le métier consomme — même séparation que `ImportWorkoutsController::candidate()`.
     * Les valeurs non nulles sont garanties par la validation, qui a déjà rendu un 422
     * sinon.
     *
     * @param list<NotificationPreferenceRequest> $preferences
     *
     * @return array<string, bool>
     */
    private static function preferencesOf(array $preferences): array
    {
        $byCategory = [];

        foreach ($preferences as $preference) {
            \assert(null !== $preference->category && null !== $preference->enabled);

            $byCategory[$preference->category->value] = $preference->enabled;
        }

        return $byCategory;
    }
}
