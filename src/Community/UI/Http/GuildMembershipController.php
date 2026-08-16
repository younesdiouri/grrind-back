<?php

declare(strict_types=1);

namespace App\Community\UI\Http;

use App\Community\Application\ExcludeMember;
use App\Community\Application\ExcludeMemberHandler;
use App\Community\Application\LeaveGuild;
use App\Community\Application\LeaveGuildHandler;
use App\Community\Domain\Exception\PlayerIsNotAMember;
use App\Community\Domain\Guild;
use App\Community\Infrastructure\Security\GuildVoter;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Sortir d'une guilde — de son plein gré, ou pas.
 *
 * Les deux routes sont volontairement dissymétriques. **Quitter ne prend aucun
 * identifiant** : on ne quitte que sa propre guilde, et un paramètre n'ouvrirait aucune
 * possibilité — on n'appartient qu'à une guilde — mais donnerait une prise à vérifier.
 * **Exclure en prend deux**, parce qu'il s'agit d'agir sur quelqu'un d'autre, et c'est
 * exactement pour ça que la route est gardée par `GUILD_KICK`.
 */
final readonly class GuildMembershipController
{
    public function __construct(
        private LeaveGuildHandler $leaveGuild,
        private ExcludeMemberHandler $excludeMember,
    ) {
    }

    #[Route('/api/guilds/mine/leave', name: 'community_guild_leave', methods: ['POST'])]
    #[OA\Tag(name: 'Guildes')]
    #[OA\Response(
        response: 204,
        description: <<<'TXT'
            Le joueur est sorti. Trois issues invisibles pour lui, et toutes dans la même transaction :
            il s'en va simplement ; il était fondateur et la guilde passe **au membre le plus ancien** ;
            il était le dernier et la guilde est dissoute, code d'invitation compris.
            TXT,
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(
        response: 404,
        description: 'Le joueur n\'a pas de guilde à quitter (`guild-not-found`).',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/ProblemDetails'),
        ),
    )]
    public function leave(#[CurrentUser] UserInterface $user): JsonResponse
    {
        ($this->leaveGuild)(new LeaveGuild(Uuid::fromString($user->getUserIdentifier())));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/guilds/{id}/members/{playerId}', name: 'community_guild_exclude', methods: ['DELETE'])]
    #[IsGranted(GuildVoter::KICK, subject: 'guild')]
    #[OA\Tag(name: 'Guildes')]
    #[OA\Response(
        response: 204,
        description: <<<'TXT'
            Le membre est sorti. **Il peut revenir avec un code valide** : il n'y a pas de liste
            noire en v1, et le recours du fondateur est de révoquer le code — ce qui referme la
            guilde pour tout le monde.
            TXT,
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 403, ref: '#/components/responses/Forbidden')]
    #[OA\Response(
        response: 404,
        description: 'La guilde est invisible à l\'appelant (`guild-not-found`), ou le joueur visé n\'en est pas membre (`player-is-not-a-member`).',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/ProblemDetails'),
        ),
    )]
    #[OA\Response(
        response: 409,
        description: 'Le fondateur a tenté de s\'exclure lui-même (`founder-cannot-exclude-himself`). Il doit passer par `POST /api/guilds/mine/leave`, qui sait gérer la succession.',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/ProblemDetails'),
        ),
    )]
    public function exclude(
        #[CurrentUser]
        UserInterface $user,
        #[VisibleGuild]
        Guild $guild,
        string $playerId,
    ): JsonResponse {
        // Un `{playerId}` malformé ne peut désigner personne : le même 404 que pour un
        // joueur absent, plutôt qu'un 400 qui distinguerait les deux sans rien apporter.
        if (!Uuid::isValid($playerId)) {
            throw new PlayerIsNotAMember();
        }

        ($this->excludeMember)(new ExcludeMember(
            $guild,
            Uuid::fromString($user->getUserIdentifier()),
            Uuid::fromString($playerId),
        ));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
