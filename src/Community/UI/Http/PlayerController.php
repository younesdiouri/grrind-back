<?php

declare(strict_types=1);

namespace App\Community\UI\Http;

use App\Community\Domain\Exception\PlayerNotFound;
use App\Community\UI\Http\Response\PlayerResource;
use App\Shared\Application\PlayerProfiles;
use App\Shared\Application\PlayerProgressions;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Le profil public d'un joueur — **la seule route de l'API qui sert les données de
 * quelqu'un d'autre**, et celle qui a fait réécrire un invariant.
 *
 * L'invariant disait « aucune route ne prend d'identifiant de compte en paramètre ». Il a
 * bien protégé le produit tant qu'il était solo, mais voir le profil d'un co-équipier,
 * c'est lire les données d'un autre compte, et aucune reformulation n'y échappe. Il n'a
 * donc pas été contourné en douce : il a été **réécrit**, dans `CLAUDE.md`,
 * `ARCHITECTURE.md` et le docblock de `MeController`.
 *
 * Ce qu'il dit maintenant : *aucune route ne prend d'identifiant de compte pour servir les
 * données du joueur courant*. `/api/me`, `/api/progression` et `/api/workouts` continuent
 * de n'en accepter aucun — c'est là qu'était le risque de détournement, et il reste fermé.
 * Une route qui sert les données de quelqu'un d'autre en prend un, et elle est gardée par
 * un voter.
 *
 * **La route vit dans `Community` et non dans `Identity`** parce que l'autorisation qu'elle
 * demande — « sommes-nous de la même guilde » — est une question à laquelle seul ce module
 * sait répondre. Elle n'est pas sous `/api/guilds` pour autant : le classement (Lot 6) aura
 * besoin du même profil, et l'enfermer dans la guilde obligerait à le republier ailleurs.
 */
final readonly class PlayerController
{
    public function __construct(
        private PlayerProfiles $profiles,
        private PlayerProgressions $progressions,
    ) {
    }

    #[Route('/api/players/{id}', name: 'community_player_show', methods: ['GET'])]
    #[OA\Tag(name: 'Guildes')]
    #[OA\Response(
        response: 200,
        description: 'Le profil public. **Exactement le bloc servi dans la liste des membres** : mêmes ports, même ressource, donc un seul type à décoder côté client.',
        content: new OA\JsonContent(ref: '#/components/schemas/Player'),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(
        response: 404,
        description: <<<'TXT'
            Le joueur n'existe pas, **ou n'est ni soi-même ni un co-équipier** (`player-not-found`).
            Les deux cas rendent la même réponse, et **jamais 403** : un 403 confirmerait qu'un
            compte porte cet UUID, et les UUID v7 encodent leur instant de création — l'API
            deviendrait un moyen d'énumérer les comptes ouverts un jour donné.
            TXT,
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/ProblemDetails'),
        ),
    )]
    public function show(#[VisiblePlayer] Uuid $id): JsonResponse
    {
        // Le voter a déjà tranché le droit de regarder ; reste à savoir s'il y a quelque
        // chose à montrer. Un joueur autorisé mais sans compte serait une adhésion
        // orpheline — impossible aujourd'hui — et le même 404 est la seule réponse qui ne
        // dise rien de plus que les autres.
        $profile = $this->profiles->of([$id])[$id->toRfc4122()] ?? null;

        if (null === $profile) {
            throw new PlayerNotFound();
        }

        $progression = $this->progressions->of([$id])[$id->toRfc4122()];

        return new JsonResponse(PlayerResource::from($id, $profile, $progression)->toArray());
    }
}
