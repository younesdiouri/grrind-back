<?php

declare(strict_types=1);

namespace App\Community\UI\Http;

use App\Community\Application\GuildWeeklyLeaderboardProvider;
use App\Community\UI\Http\Response\GuildWeeklyLeaderboardResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

final readonly class GuildWeeklyLeaderboardController
{
    public function __construct(private GuildWeeklyLeaderboardProvider $leaderboard)
    {
    }

    #[Route('/api/guilds/mine/weekly-leaderboard', name: 'community_guild_weekly_leaderboard', methods: ['GET'])]
    #[OA\Tag(name: 'Guildes')]
    #[OA\Response(
        response: 200,
        description: 'Les membres actuels classés par nombre de séances validées terminées dans la semaine civile Europe/Paris, depuis leur adhésion actuelle. Aucun gain associé.',
        content: new OA\JsonContent(ref: '#/components/schemas/GuildWeeklyLeaderboard'),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(
        response: 404,
        description: 'Le joueur n\'a pas de guilde (`guild-not-found`).',
        content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/ProblemDetails')),
    )]
    public function __invoke(#[CurrentUser] UserInterface $user): JsonResponse
    {
        $board = $this->leaderboard->of(Uuid::fromString($user->getUserIdentifier()));

        return new JsonResponse(new GuildWeeklyLeaderboardResource($board)->toArray());
    }
}
