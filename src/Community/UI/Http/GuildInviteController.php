<?php

declare(strict_types=1);

namespace App\Community\UI\Http;

use App\Community\Application\IssueInviteCode;
use App\Community\Application\IssueInviteCodeHandler;
use App\Community\Application\JoinGuild;
use App\Community\Application\JoinGuildHandler;
use App\Community\Application\RevokeInviteCode;
use App\Community\Application\RevokeInviteCodeHandler;
use App\Community\Domain\Guild;
use App\Community\Domain\GuildRules;
use App\Community\Infrastructure\Security\GuildVoter;
use App\Community\UI\Http\Request\JoinGuildRequest;
use App\Community\UI\Http\Response\GuildResource;
use App\Community\UI\Http\Response\InviteCodeResource;
use OpenApi\Attributes as OA;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Attribute\RateLimit;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Le code d'invitation : le générer, le couper, l'utiliser.
 *
 * **« Inviter ses amis » sans annuaire, et c'est volontaire.** Aucun `handle` unique
 * n'existe, `displayName` n'est pas unique, et une recherche par adresse exacte rendrait
 * l'API capable de confirmer qu'une adresse a un compte. Le code d'invitation ne résout
 * pas le problème de l'annuaire : il le rend inutile.
 *
 * Les deux routes de gestion sont fondateur seul et prennent la guilde par
 * `#[VisibleGuild]`, donc un non-membre y reçoit 404 comme partout ailleurs. `join`, elle,
 * ne prend **aucun identifiant de guilde** : c'est le code qui la désigne, sans quoi il
 * suffirait d'un UUID pour entrer.
 */
final readonly class GuildInviteController
{
    public function __construct(
        private IssueInviteCodeHandler $issueInviteCode,
        private RevokeInviteCodeHandler $revokeInviteCode,
        private JoinGuildHandler $joinGuild,
        private GuildRules $rules,
    ) {
    }

    #[Route('/api/guilds/{id}/invite-code', name: 'community_invite_code_issue', methods: ['POST'])]
    #[IsGranted(GuildVoter::EDIT, subject: 'guild')]
    #[OA\Tag(name: 'Guildes')]
    #[OA\Response(
        response: 201,
        description: 'Le nouveau code. **Il révoque le précédent** : une guilde n\'a jamais deux codes vivants, et régénérer est le geste par lequel on coupe un code qui a trop circulé.',
        content: new OA\JsonContent(ref: '#/components/schemas/GuildInviteCode'),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 403, ref: '#/components/responses/Forbidden')]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    public function issue(#[VisibleGuild] Guild $guild): JsonResponse
    {
        $code = ($this->issueInviteCode)(new IssueInviteCode($guild));

        return new JsonResponse(InviteCodeResource::from($code)->toArray(), Response::HTTP_CREATED);
    }

    #[Route('/api/guilds/{id}/invite-code', name: 'community_invite_code_revoke', methods: ['DELETE'])]
    #[IsGranted(GuildVoter::EDIT, subject: 'guild')]
    #[OA\Tag(name: 'Guildes')]
    #[OA\Response(
        response: 204,
        description: 'Plus aucun code ne mène à cette guilde. Révoquer alors qu\'il n\'y avait rien à révoquer rend le même 204 : l\'état visé est atteint.',
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 403, ref: '#/components/responses/Forbidden')]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    public function revoke(#[VisibleGuild] Guild $guild): JsonResponse
    {
        ($this->revokeInviteCode)(new RevokeInviteCode($guild));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Le limiteur est **configuré, pas écrit** : la politique et les seuils vivent dans
     * `config/packages/rate_limiter.yaml`, et l'attribut ne fait que désigner la clé.
     *
     * Cette clé est le joueur et non l'IP — une IP mobile change en cours de trajet et se
     * partage entre colocataires, alors que seul un compte authentifié peut consommer un
     * code. `args["user"]` est l'argument du contrôleur, disponible parce que l'attribut
     * est traité sur `kernel.controller_arguments`, après résolution.
     */
    #[Route('/api/guilds/join', name: 'community_guild_join', methods: ['POST'])]
    #[RateLimit('guild_join', key: new Expression('args["user"].getUserIdentifier()'))]
    #[OA\Tag(name: 'Guildes')]
    #[OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GuildJoin'))]
    #[OA\Response(
        response: 200,
        description: 'Le joueur est entré. La guilde est rendue en entier, pour que l\'écran s\'affiche sans second appel.',
        content: new OA\JsonContent(ref: '#/components/schemas/Guild'),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(
        response: 404,
        description: 'Le code ne mène à rien : **inconnu, expiré ou révoqué, indistinctement** (`invite-code-not-usable`). Les distinguer dirait quels codes existent.',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/ProblemDetails'),
        ),
    )]
    #[OA\Response(
        response: 409,
        description: 'Le joueur appartient déjà à une guilde (`player-already-in-a-guild`), ou celle-ci est complète (`guild-is-full`, qui porte sa `capacity`).',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/ProblemDetails'),
        ),
    )]
    #[OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntity')]
    #[OA\Response(response: 429, ref: '#/components/responses/TooManyRequests')]
    public function join(
        #[CurrentUser]
        UserInterface $user,
        #[MapRequestPayload]
        JoinGuildRequest $request,
    ): JsonResponse {
        $playerId = Uuid::fromString($user->getUserIdentifier());

        // Le code arrive déjà normalisé : c'est le DTO qui s'en charge, avant que la
        // validation ne s'applique — voir {@see JoinGuildRequest}.
        $guild = ($this->joinGuild)(new JoinGuild($playerId, $request->code));

        return new JsonResponse(GuildResource::from($guild, $playerId, $this->rules->maximumMembers)->toArray());
    }
}
