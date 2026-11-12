<?php

declare(strict_types=1);

namespace App\Training\UI\Http;

use App\Training\Application\WorkoutStatsProvider;
use App\Training\UI\Http\Request\WorkoutStatsQuery;
use App\Training\UI\Http\Response\WorkoutStatsResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * CurrentUser et MapQueryString gardent l'identité et la validation aux frontières,
 * comme pour l'historique. Aucun identifiant, fuseau ou instant n'est fourni par le client.
 * https://symfony.com/doc/current/controller.html#mapping-the-whole-query-string.
 */
final readonly class WorkoutStatsController
{
    public function __construct(private WorkoutStatsProvider $stats)
    {
    }

    #[Route('/api/workouts/stats', name: 'training_workout_stats', methods: ['GET'])]
    #[OA\Tag(name: 'Entraînement')]
    #[OA\Response(response: 200, description: 'Statistiques de volume du joueur courant dans son fuseau de profil.', content: new OA\JsonContent(ref: '#/components/schemas/WorkoutStats'))]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntity')]
    public function __invoke(
        #[CurrentUser]
        UserInterface $user,
        #[MapQueryString]
        WorkoutStatsQuery $query,
    ): JsonResponse {
        return new JsonResponse(WorkoutStatsResource::from($this->stats->of(Uuid::fromString($user->getUserIdentifier()), $query->period)));
    }
}
