<?php

declare(strict_types=1);

namespace App\Community\UI\Http;

use App\Community\Application\GuildMembersProvider;
use App\Community\Domain\Guild;
use App\Community\Domain\GuildRules;
use App\Community\Infrastructure\Doctrine\GuildMembershipRepository;
use App\Community\UI\Http\Response\GuildDetailResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Voir sa guilde, et voir qui en est.
 *
 * **Les deux routes `GET` vivent dans le même fichier, et `mine` est déclarée d'abord.**
 * Ce n'est pas une préférence de rangement : `/api/guilds/mine` et `/api/guilds/{id}`
 * s'attrapent mutuellement, et Symfony les essaie dans l'ordre de déclaration. Les séparer
 * en deux contrôleurs ferait dépendre le résultat de l'ordre de chargement des fichiers,
 * qui n'est garanti nulle part. Deux tests le tiennent, dont celui d'un joueur sans guilde
 * — c'est exactement le cas que `{id}` avalerait en premier.
 */
final readonly class GuildMembersController
{
    public function __construct(
        private GuildMembershipRepository $memberships,
        private GuildMembersProvider $members,
        private GuildRules $rules,
    ) {
    }

    #[Route('/api/guilds/mine', name: 'community_guild_mine', methods: ['GET'])]
    #[OA\Tag(name: 'Guildes')]
    #[OA\Response(
        response: 200,
        description: 'La guilde du joueur et ses membres, ou `{"guild": null}` s\'il n\'en a pas. **Pas d\'erreur dans ce second cas** : ouvrir l\'onglet quand on n\'a pas de guilde est une situation normale, pas une panne, et c\'est l\'écran qui invite à en fonder une.',
        content: new OA\JsonContent(ref: '#/components/schemas/MyGuild'),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    public function mine(#[CurrentUser] UserInterface $user): JsonResponse
    {
        $playerId = Uuid::fromString($user->getUserIdentifier());
        $membership = $this->memberships->ofPlayer($playerId);

        if (null === $membership) {
            return new JsonResponse(['guild' => null]);
        }

        $guild = $membership->guild();

        return new JsonResponse(['guild' => $this->detail($guild, $playerId)]);
    }

    #[Route('/api/guilds/{id}', name: 'community_guild_show', methods: ['GET'])]
    #[OA\Tag(name: 'Guildes')]
    #[OA\Response(
        response: 200,
        description: 'La même charge utile que `/api/guilds/mine`, sans l\'enveloppe : ici la guilde existe forcément, puisqu\'un non-membre reçoit 404.',
        content: new OA\JsonContent(ref: '#/components/schemas/GuildDetail'),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    public function show(#[CurrentUser] UserInterface $user, #[VisibleGuild] Guild $guild): JsonResponse
    {
        return new JsonResponse($this->detail($guild, Uuid::fromString($user->getUserIdentifier())));
    }

    /**
     * `GUILD_VIEW` n'apparaît pas sur `show()` : c'est `#[VisibleGuild]` qui l'exige, et
     * qui rend 404 plutôt que 403 quand il manque. Un `#[IsGranted]` en plus serait mort
     * — le resolver a déjà refusé — et donnerait l'impression que c'est lui qui protège.
     *
     * @return array<string, mixed>
     */
    private function detail(Guild $guild, Uuid $playerId): array
    {
        return GuildDetailResource::from(
            $guild,
            $this->members->of($guild),
            $playerId,
            $this->rules->maximumMembers(),
        )->toArray();
    }
}
