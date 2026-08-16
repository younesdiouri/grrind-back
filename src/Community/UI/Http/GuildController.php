<?php

declare(strict_types=1);

namespace App\Community\UI\Http;

use App\Community\Application\DissolveGuild;
use App\Community\Application\DissolveGuildHandler;
use App\Community\Application\FoundGuild;
use App\Community\Application\FoundGuildHandler;
use App\Community\Application\RenameGuild;
use App\Community\Application\RenameGuildHandler;
use App\Community\Domain\Guild;
use App\Community\Domain\GuildRules;
use App\Community\Infrastructure\Security\GuildVoter;
use App\Community\UI\Http\Request\FoundGuildRequest;
use App\Community\UI\Http\Request\RenameGuildRequest;
use App\Community\UI\Http\Response\GuildResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * La vie d'une guilde : la fonder, la renommer, la dissoudre.
 *
 * **Aucun `if` d'autorisation dans ce fichier**, et c'est la propriété qu'il faut garder.
 * Deux mécanismes s'en chargent, et ils ne rendent pas le même code :
 *
 *  - `#[VisibleGuild]` charge la guilde et rend **404** si l'appelant n'a rien à y voir —
 *    guilde inconnue et non-membre confondus, sans quoi la route dirait quels UUID
 *    existent ;
 *  - `#[IsGranted]` interroge {@see GuildVoter} et rend **403** au membre qui existe mais
 *    n'est pas fondateur. Lui sait déjà que la guilde existe : il en fait partie.
 *
 * Le joueur vient de `#[CurrentUser]`, jamais du corps ni de l'URL. `UserInterface` et non
 * l'entité `User` : Deptrac interdit à `Community` d'importer `Identity`, et l'UUID du
 * compte suffit — c'est précisément ce que porte `getUserIdentifier()`.
 */
final readonly class GuildController
{
    public function __construct(
        private FoundGuildHandler $foundGuild,
        private RenameGuildHandler $renameGuild,
        private DissolveGuildHandler $dissolveGuild,
        private GuildRules $rules,
    ) {
    }

    #[Route('/api/guilds', name: 'community_guild_found', methods: ['POST'])]
    #[OA\Tag(name: 'Guildes')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GuildName'))]
    #[OA\Response(
        response: 201,
        description: 'La guilde est fondée et l\'appelant en est le fondateur : les deux dans le même geste.',
        content: new OA\JsonContent(ref: '#/components/schemas/Guild'),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(
        response: 409,
        description: 'Le joueur appartient déjà à une guilde (`player-already-in-a-guild`). Il doit la quitter avant d\'en fonder une autre.',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/ProblemDetails'),
        ),
    )]
    #[OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntity')]
    public function found(
        #[CurrentUser]
        UserInterface $user,
        #[MapRequestPayload]
        FoundGuildRequest $request,
    ): JsonResponse {
        $playerId = Uuid::fromString($user->getUserIdentifier());

        $guild = ($this->foundGuild)(new FoundGuild($playerId, $request->name));

        return new JsonResponse(
            GuildResource::from($guild, $playerId, $this->rules->maximumMembers)->toArray(),
            Response::HTTP_CREATED,
        );
    }

    #[Route('/api/guilds/{id}', name: 'community_guild_rename', methods: ['PATCH'])]
    #[IsGranted(GuildVoter::EDIT, subject: 'guild')]
    #[OA\Tag(name: 'Guildes')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GuildName'))]
    #[OA\Response(
        response: 200,
        description: 'La guilde renommée.',
        content: new OA\JsonContent(ref: '#/components/schemas/Guild'),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 403, ref: '#/components/responses/Forbidden')]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    #[OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntity')]
    public function rename(
        #[CurrentUser]
        UserInterface $user,
        #[VisibleGuild]
        Guild $guild,
        #[MapRequestPayload]
        RenameGuildRequest $request,
    ): JsonResponse {
        $renamed = ($this->renameGuild)(new RenameGuild($guild, $request->name));

        return new JsonResponse(
            GuildResource::from($renamed, Uuid::fromString($user->getUserIdentifier()), $this->rules->maximumMembers)->toArray(),
        );
    }

    #[Route('/api/guilds/{id}', name: 'community_guild_dissolve', methods: ['DELETE'])]
    #[IsGranted(GuildVoter::DISSOLVE, subject: 'guild')]
    #[OA\Tag(name: 'Guildes')]
    #[OA\Response(
        response: 204,
        description: 'La guilde et toutes ses adhésions sont parties dans la même transaction. Ses membres sont libres d\'en fonder ou d\'en rejoindre une autre.',
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 403, ref: '#/components/responses/Forbidden')]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    public function dissolve(#[VisibleGuild] Guild $guild): JsonResponse
    {
        ($this->dissolveGuild)(new DissolveGuild($guild));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
