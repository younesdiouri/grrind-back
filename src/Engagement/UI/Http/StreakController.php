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

/** Le streak de l'assiduité du joueur courant (#346), à son jour local. */
final readonly class StreakController
{
    public function __construct(private StreakTracker $streaks)
    {
    }

    #[Route('/api/streak', name: 'streak_state', methods: ['GET'])]
    #[OA\Tag(name: 'Engagement')]
    #[OA\Response(
        response: 200,
        description: 'Le streak en cours, sans rien pointer.',
        content: new OA\JsonContent(ref: '#/components/schemas/StreakState'),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    public function show(#[CurrentUser] UserInterface $user): JsonResponse
    {
        return new JsonResponse($this->streaks->current(Uuid::fromString($user->getUserIdentifier()))->toArray());
    }

    #[Route('/api/streak/check-in', name: 'streak_check_in', methods: ['POST'])]
    #[OA\Tag(name: 'Engagement')]
    #[OA\Response(
        response: 200,
        description: 'Le jour est pointé — idempotent, un second appel le même jour ne change rien.',
        content: new OA\JsonContent(ref: '#/components/schemas/StreakCheckIn'),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    public function checkIn(#[CurrentUser] UserInterface $user): JsonResponse
    {
        $result = $this->streaks->checkIn(Uuid::fromString($user->getUserIdentifier()));

        return new JsonResponse(['streak' => $result['streak']->toArray(), 'chest' => $result['chest']?->toArray()]);
    }
}
