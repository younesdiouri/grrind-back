<?php

declare(strict_types=1);

namespace App\Engagement\UI\Http;

use App\Engagement\Application\StreakTracker;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/** La série de régularité du joueur courant (#286), évaluée à son jour local. */
final readonly class StreakController
{
    public function __construct(private StreakTracker $streaks)
    {
    }

    #[Route('/api/streak', name: 'streak_state', methods: ['GET'])]
    #[OA\Tag(name: 'Engagement')]
    #[OA\Response(
        response: 200,
        description: 'La série en cours, relue des jours sportifs — aucun compteur stocké.',
        content: new OA\JsonContent(ref: '#/components/schemas/StreakState'),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    public function show(#[CurrentUser] UserInterface $user): JsonResponse
    {
        return new JsonResponse($this->streaks->current(Uuid::fromString($user->getUserIdentifier()))->toArray());
    }
}
