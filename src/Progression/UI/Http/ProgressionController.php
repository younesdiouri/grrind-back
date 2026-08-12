<?php

declare(strict_types=1);

namespace App\Progression\UI\Http;

use App\Progression\Application\ListXpHistory;
use App\Progression\Application\ListXpHistoryHandler;
use App\Progression\Application\ProgressionStateProvider;
use App\Progression\Infrastructure\Translation\TitleTranslator;
use App\Progression\UI\Http\Request\XpHistoryQuery;
use App\Progression\UI\Http\Response\ProgressionResource;
use App\Progression\UI\Http\Response\XpHistoryPageResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * L'état du joueur, et d'où il vient.
 *
 * **Deux routes et non une.** L'état tient dans une réponse de taille fixe que le client
 * demande à chaque ouverture ; l'historique est une liste qui grandit sans fin et qu'il ne
 * charge que si le joueur descend l'écran. Les servir ensemble ferait payer le second à
 * chaque affichage du premier.
 *
 * Le joueur vient du jeton : aucune route ne prend d'identifiant de compte, donc aucune ne
 * peut être détournée pour lire la progression d'un autre.
 */
final readonly class ProgressionController
{
    public function __construct(
        private ProgressionStateProvider $states,
        private ListXpHistoryHandler $listHistory,
        private TitleTranslator $titles,
    ) {
    }

    #[Route('/api/progression', name: 'progression_state', methods: ['GET'])]
    #[OA\Tag(name: 'Progression')]
    #[OA\Response(
        response: 200,
        description: 'L\'état du joueur, servi du seul snapshot — aucune relecture du ledger.',
        content: new OA\JsonContent(ref: '#/components/schemas/Progression'),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    public function show(#[CurrentUser] UserInterface $user): JsonResponse
    {
        $state = $this->states->of(Uuid::fromString($user->getUserIdentifier()));

        return new JsonResponse(ProgressionResource::from($state, $this->titles)->toArray());
    }

    /**
     * L'argument `#[MapQueryString]` n'est ni nullable ni pourvu d'un défaut, et c'est
     * voulu : le résolveur ne construit le DTO sur une query string vide que dans ce
     * cas-là. Nullable, il faudrait un `?? new XpHistoryQuery()` à la main.
     */
    #[Route('/api/progression/history', name: 'progression_history', methods: ['GET'])]
    #[OA\Tag(name: 'Progression')]
    #[OA\Response(
        response: 200,
        description: 'Une page du ledger, la plus récente d\'abord. C\'est la vérité dont le niveau est une projection.',
        content: new OA\JsonContent(ref: '#/components/schemas/XpHistoryPage'),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntity')]
    public function history(
        #[CurrentUser]
        UserInterface $user,
        #[MapQueryString]
        XpHistoryQuery $query,
    ): JsonResponse {
        $page = ($this->listHistory)(new ListXpHistory(
            Uuid::fromString($user->getUserIdentifier()),
            $query->cursor,
            $query->limit,
        ));

        return new JsonResponse(XpHistoryPageResource::from($page)->toArray());
    }
}
