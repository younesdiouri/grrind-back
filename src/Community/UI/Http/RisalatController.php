<?php

declare(strict_types=1);

namespace App\Community\UI\Http;

use App\Community\Application\ChooseRisala;
use App\Community\Application\ChooseRisalaHandler;
use App\Community\Application\RisalatBoardProvider;
use App\Community\UI\Http\Request\ChooseRisalaRequest;
use App\Community\UI\Http\Response\RisalatResource;
use App\Shared\Domain\Activity\Discipline;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Voir les Risālāt de sa guilde, et choisir la sienne quand c'est son tour.
 *
 * **Les deux routes sont sur `mine` et ne prennent aucun identifiant.** On ne lit les Risālāt
 * que de sa propre guilde, et on n'honore que son propre tour : un paramètre n'ouvrirait
 * aucune possibilité — on n'appartient qu'à une guilde, elle n'a qu'un tour ouvert — mais
 * donnerait une prise à vérifier. Même dissymétrie qu'à {@see GuildMembershipController}.
 *
 * **`PUT` et non `POST`** : le choix se refait tant que l'échéance n'est pas passée, et la
 * même requête deux fois laisse le même état. Le verbe dit ce que la mécanique autorise — on
 * change d'avis sur un sport qu'on propose aux autres.
 *
 * Les deux rendent **la même charge utile**, l'écran complet : après un choix, le client n'a
 * rien à recharger, et `choosable` reflète déjà la nouvelle situation.
 */
final readonly class RisalatController
{
    public function __construct(
        private RisalatBoardProvider $board,
        private ChooseRisalaHandler $chooseRisala,
    ) {
    }

    #[Route('/api/guilds/mine/risalat', name: 'community_risalat_show', methods: ['GET'])]
    #[OA\Tag(name: 'Guildes')]
    #[OA\Response(
        response: 200,
        description: <<<'TXT'
            Les Risālāt vivantes **dans l'ordre de révélation** — qui est aussi celui de leur
            extinction — et le tour en cours. Deux en régime établi, une seule après un tour manqué,
            aucune la première quinzaine d'une guilde neuve.
            TXT,
        content: new OA\JsonContent(ref: '#/components/schemas/Risalat'),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(
        response: 404,
        description: 'Le joueur n\'a pas de guilde (`guild-not-found`). Contrairement à `GET /api/guilds/mine`, cet écran n\'existe qu\'à l\'intérieur d\'une guilde.',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/ProblemDetails'),
        ),
    )]
    public function show(#[CurrentUser] UserInterface $user): JsonResponse
    {
        return new JsonResponse(RisalatResource::from($this->board->of(Uuid::fromString($user->getUserIdentifier())))->toArray());
    }

    #[Route('/api/guilds/mine/risalat/turn', name: 'community_risala_choose', methods: ['PUT'])]
    #[OA\Tag(name: 'Guildes')]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['discipline'],
            properties: [new OA\Property(
                property: 'discipline',
                description: 'Le sport envoyé à la guilde. Doit figurer dans le `choosable` du tour.',
                type: 'string',
                enum: [Discipline::class],
            )],
        ),
    )]
    #[OA\Response(
        response: 200,
        description: 'Le choix est enregistré, et l\'écran complet est rendu — rien à recharger.',
        content: new OA\JsonContent(ref: '#/components/schemas/Risalat'),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(
        response: 403,
        description: <<<'TXT'
            Le tour appartient à quelqu'un d'autre (`risala-turn-is-not-yours`). **403 et non 404**,
            contrairement au reste du module : le refus ne protège rien, puisque
            `GET /api/guilds/mine/risalat` a déjà dit à l'appelant à qui appartient le tour.
            TXT,
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/ProblemDetails'),
        ),
    )]
    #[OA\Response(
        response: 404,
        description: <<<'TXT'
            Pas de guilde (`guild-not-found`), ou aucun tour ouvert (`risala-turn-is-not-open`) —
            une guilde d'un seul membre n'en tire pas, et une guilde neuve attend la bascule.
            TXT,
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/ProblemDetails'),
        ),
    )]
    #[OA\Response(
        response: 422,
        description: <<<'TXT'
            Trois refus, un `type` chacun : l'échéance est passée (`risala-turn-is-closed`), la
            discipline ne rapporte pas d'XP (`discipline-does-not-credit`), ou une Risāla vivante la
            porte déjà (`discipline-already-challenged`). Le premier ne se réessaie pas ; les deux
            autres se corrigent en choisissant dans `choosable`.
            TXT,
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/ProblemDetails'),
        ),
    )]
    public function choose(#[CurrentUser] UserInterface $user, #[MapRequestPayload] ChooseRisalaRequest $request): JsonResponse
    {
        // `discipline` est déclarée nullable pour que l'absence produise une violation lisible
        // plutôt qu'une erreur de désérialisation ; l'assertion a déjà tranché quand on arrive
        // ici.
        \assert(null !== $request->discipline);

        $board = ($this->chooseRisala)(new ChooseRisala(Uuid::fromString($user->getUserIdentifier()), $request->discipline));

        return new JsonResponse(RisalatResource::from($board)->toArray());
    }
}
