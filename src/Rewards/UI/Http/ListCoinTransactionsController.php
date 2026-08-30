<?php

declare(strict_types=1);

namespace App\Rewards\UI\Http;

use App\Rewards\Application\CoinLedger;
use App\Rewards\Application\ListCoinHistory;
use App\Rewards\Application\ListCoinHistoryHandler;
use App\Rewards\UI\Http\Request\CoinHistoryQuery;
use App\Rewards\UI\Http\Response\CoinHistoryPageResource;
use App\Shared\UI\Http\Cursor;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * La bourse : le solde, et le détail de chaque mouvement, du plus récent au plus ancien
 * (#30). Une route à part de `GET /api/inventory` — même séparation que
 * `GET /api/battles`/`GET /api/battles/{id}` — parce que celui-ci sert un écran qui se
 * défile, quand `GET /api/inventory` répond juste « combien j'ai ».
 *
 * **Le tri est celui du fait, exactement comme `GET /api/battles` et
 * `GET /api/progression/history`** — voir le docblock de
 * {@see \App\Rewards\Infrastructure\Doctrine\CoinTransactionRepository::history()} pour
 * pourquoi une pièce créditée par un vieux workout doit se ranger à la date de ce workout,
 * pas à celle de l'écriture. Le curseur et l'enveloppe restent la même forme, voir
 * {@see Cursor}.
 *
 * Aucun filtre, même raisonnement qu'à `BattleHistoryQuery` : aucun écran n'en réclame.
 */
final readonly class ListCoinTransactionsController
{
    public function __construct(
        private ListCoinHistoryHandler $listHistory,
        private CoinLedger $coins,
    ) {
    }

    #[Route('/api/inventory/coins', name: 'rewards_coin_history', methods: ['GET'])]
    #[OA\Tag(name: 'Récompenses')]
    #[OA\Response(
        response: 200,
        description: 'Le solde, et une page de mouvements du plus récent au plus ancien.',
        content: new OA\JsonContent(ref: '#/components/schemas/CoinHistoryPage'),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntity')]
    public function __invoke(
        #[CurrentUser]
        UserInterface $user,
        #[MapQueryString]
        CoinHistoryQuery $query,
    ): JsonResponse {
        $userId = Uuid::fromString($user->getUserIdentifier());

        $page = ($this->listHistory)(new ListCoinHistory(
            $userId,
            Cursor::fromQuery($query, $query->cursor),
            $query->limit,
        ));

        return new JsonResponse(CoinHistoryPageResource::from($this->coins->balanceOf($userId), $page)->toArray());
    }
}
