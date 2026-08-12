<?php

declare(strict_types=1);

namespace App\Progression\UI\Http;

use App\Progression\Application\SelectTitle;
use App\Progression\Application\SelectTitleHandler;
use App\Progression\Application\TitleBoardProvider;
use App\Progression\Infrastructure\Translation\TitleTranslator;
use App\Progression\UI\Http\Request\SelectTitleRequest;
use App\Progression\UI\Http\Response\TitleBoardResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Le mur des titres, et le choix de celui qu'on porte.
 *
 * Le joueur vient du jeton : aucune route ne prend d'identifiant de compte, donc aucune ne
 * peut être détournée pour porter un titre au nom d'un autre.
 *
 * `PUT` et non `POST` : reposer deux fois le même titre laisse le compte dans le même état.
 * Les deux verbes rendent **la même ressource** — le mur entier — pour que le client
 * rafraîchisse son écran sans second appel après une sélection.
 */
final readonly class TitlesController
{
    public function __construct(
        private TitleBoardProvider $boards,
        private SelectTitleHandler $selectTitle,
        private TitleTranslator $titles,
    ) {
    }

    #[Route('/api/titles', name: 'progression_titles', methods: ['GET'])]
    #[OA\Tag(name: 'Progression')]
    #[OA\Response(
        response: 200,
        description: 'Le catalogue entier, situé pour ce joueur.',
        content: new OA\JsonContent(ref: '#/components/schemas/TitleBoard'),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    public function index(#[CurrentUser] UserInterface $user): JsonResponse
    {
        return $this->board($user);
    }

    #[Route('/api/titles/active', name: 'progression_titles_select', methods: ['PUT'])]
    #[OA\Tag(name: 'Progression')]
    #[OA\Response(
        response: 200,
        description: 'Le mur des titres, avec le nouveau titre porté. `titleId` à `null` n\'en affiche aucun.',
        content: new OA\JsonContent(ref: '#/components/schemas/TitleBoard'),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(
        response: 409,
        description: 'Titre inconnu du catalogue (`title-unknown`) ou non débloqué par ce joueur (`title-not-unlocked`).',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/ProblemDetails'),
        ),
    )]
    #[OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntity')]
    public function select(
        #[CurrentUser]
        UserInterface $user,
        #[MapRequestPayload]
        SelectTitleRequest $request,
    ): JsonResponse {
        ($this->selectTitle)(new SelectTitle(Uuid::fromString($user->getUserIdentifier()), $request->titleId));

        return $this->board($user);
    }

    private function board(UserInterface $user): JsonResponse
    {
        $board = $this->boards->of(Uuid::fromString($user->getUserIdentifier()));

        return new JsonResponse(TitleBoardResource::from($board, $this->titles)->toArray());
    }
}
