<?php

declare(strict_types=1);

namespace App\Rewards\UI\Http;

use App\Rewards\Application\PurchaseItem;
use App\Rewards\Application\PurchaseItemHandler;
use App\Rewards\Application\ShopOverviewProvider;
use App\Rewards\Infrastructure\Translation\ItemTranslator;
use App\Rewards\UI\Http\Request\PurchaseItemRequest;
use App\Rewards\UI\Http\Response\PurchaseResource;
use App\Rewards\UI\Http\Response\ShopResource;
use App\Shared\UI\Http\Idempotent;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * La boutique (#229) : l'étal, et le seul geste qu'on peut y faire — acheter. Aucun
 * identifiant de compte sur aucune route, même geste qu'`InventoryController` : la boutique
 * n'est pas une donnée de co-équipier, la question du voter et du « 404 jamais 403 » ne se
 * pose pas ici.
 *
 * **Ni revente ni rachat.** Voir « Ce qu'on ne fait pas » du ticket : ce sont des décisions de
 * game design à part entière, pas un corollaire de l'achat — cette classe n'expose donc que
 * `GET` et un seul `POST`.
 */
final readonly class ShopController
{
    public function __construct(
        private ShopOverviewProvider $overview,
        private PurchaseItemHandler $purchase,
        private ItemTranslator $translator,
    ) {
    }

    #[Route('/api/shop', name: 'rewards_shop_show', methods: ['GET'])]
    #[OA\Tag(name: 'Récompenses')]
    #[OA\Response(
        response: 200,
        description: "L'étal — les EPIC et LEGENDARY n'y figurent jamais, voir le docblock d'ItemCatalog — avec ce que le joueur en sait et le solde de sa bourse. Un objet verrouillé par le niveau reste visible.",
        content: new OA\JsonContent(ref: '#/components/schemas/Shop'),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    public function index(#[CurrentUser] UserInterface $user): JsonResponse
    {
        $overview = $this->overview->of(Uuid::fromString($user->getUserIdentifier()));

        return new JsonResponse(ShopResource::from($overview, $this->translator)->toArray());
    }

    #[Route('/api/shop/purchases', name: 'rewards_shop_purchase', methods: ['POST'])]
    #[Idempotent]
    #[OA\Tag(name: 'Récompenses')]
    #[OA\Parameter(ref: '#/components/parameters/IdempotencyKey')]
    #[OA\Response(
        response: 201,
        description: "L'achat est écrit : l'objet, ce qu'il a coûté, le solde de pièces avant et après.",
        content: new OA\JsonContent(ref: '#/components/schemas/Purchase'),
    )]
    #[OA\Response(response: 400, ref: '#/components/responses/BadRequest')]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 409, ref: '#/components/responses/Conflict')]
    #[OA\Response(
        response: 422,
        description: 'Clé inconnue ou objet hors étal (`item-not-purchasable`), niveau insuffisant (`shop-level-too-low`), objet déjà possédé (`item-already-owned`), ou solde insuffisant (`insufficient-coin-balance`).',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/ProblemDetails'),
        ),
    )]
    public function purchase(
        #[CurrentUser]
        UserInterface $user,
        #[MapRequestPayload]
        PurchaseItemRequest $request,
    ): JsonResponse {
        $receipt = ($this->purchase)(new PurchaseItem(Uuid::fromString($user->getUserIdentifier()), $request->itemKey));

        return new JsonResponse(PurchaseResource::from($receipt, $this->translator)->toArray(), Response::HTTP_CREATED);
    }
}
