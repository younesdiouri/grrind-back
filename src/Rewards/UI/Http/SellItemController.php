<?php

declare(strict_types=1);

namespace App\Rewards\UI\Http;

use App\Rewards\Application\SellItem;
use App\Rewards\Application\SellItemHandler;
use App\Rewards\UI\Http\Request\SellItemRequest;
use App\Shared\UI\Http\Idempotent;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

final readonly class SellItemController
{
    public function __construct(private SellItemHandler $sell)
    {
    }

    #[Route('/api/inventory/sales', name: 'rewards_inventory_sale', methods: ['POST'])]
    #[Idempotent]
    #[OA\Tag(name: 'Récompenses')]
    #[OA\Parameter(ref: '#/components/parameters/IdempotencyKey')]
    #[OA\Response(response: 200, description: 'Un exemplaire vendu au prix publié, quantité restante et soldes sous verrou.', content: new OA\JsonContent(ref: '#/components/schemas/Sale'))]
    #[OA\Response(response: 400, ref: '#/components/responses/BadRequest')]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 409, ref: '#/components/responses/Conflict')]
    #[OA\Response(response: 422, description: 'Validation, item-not-owned, item-not-sellable, item-equipped ou sale-price-changed. Recharger l’inventaire et confirmer le nouveau prix après sale-price-changed.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/ProblemDetails')))]
    public function __invoke(#[CurrentUser] UserInterface $user, #[MapRequestPayload] SellItemRequest $request): JsonResponse
    {
        $receipt = ($this->sell)(new SellItem(Uuid::fromString($user->getUserIdentifier()), $request->itemKey, $request->expectedSellPriceCoins));

        return new JsonResponse([
            'itemKey' => $receipt->itemKey,
            'quantity' => $receipt->quantity,
            'coins' => $receipt->coins,
            'coinsBefore' => $receipt->coinsBefore,
            'coinsAfter' => $receipt->coinsAfter,
        ]);
    }
}
