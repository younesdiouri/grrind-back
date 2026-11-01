<?php

declare(strict_types=1);

namespace App\Engagement\UI\Http;

use App\Engagement\Application\LeagueStateProvider;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

final readonly class LeagueController
{
    public function __construct(private LeagueStateProvider $leagues)
    {
    }

    #[Route('/api/league', name: 'league_state', methods: ['GET'])]
    #[OA\Tag(name: 'Engagement')]
    #[OA\Response(response: 200, description: 'La ligue du joueur, sa course hebdomadaire provisoire et son dernier résultat figé.', content: new OA\JsonContent(ref: '#/components/schemas/LeagueState'))]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    public function show(#[CurrentUser] UserInterface $user): JsonResponse
    {
        return new JsonResponse($this->leagues->current(Uuid::fromString($user->getUserIdentifier())));
    }
}
