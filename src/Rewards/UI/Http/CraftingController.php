<?php

declare(strict_types=1);

namespace App\Rewards\UI\Http;

use App\Rewards\Application\Crafting;
use App\Rewards\UI\Http\Request\CraftItemRequest;
use App\Shared\UI\Http\Idempotent;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

final readonly class CraftingController
{
    public function __construct(private Crafting $crafting)
    {
    }

    #[Route('/api/crafting/recipes', name: 'rewards_crafting_recipes', methods: ['GET'])]
    #[OA\Tag(name: 'Récompenses')]
    #[OA\Response(response: 200, description: 'Recettes publiées, coûts et quantités possédées.', content: new OA\JsonContent(ref: '#/components/schemas/CraftingRecipes'))]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    public function recipes(#[CurrentUser] UserInterface $user): JsonResponse
    {
        return new JsonResponse($this->crafting->recipes(Uuid::fromString($user->getUserIdentifier())));
    }

    #[Route('/api/crafting', name: 'rewards_crafting_create', methods: ['POST'])]
    #[Idempotent]
    #[OA\Tag(name: 'Récompenses')]
    #[OA\Parameter(ref: '#/components/parameters/IdempotencyKey')]
    #[OA\Response(response: 201, description: 'Fabrication atomique, quantités possédées après échange.', content: new OA\JsonContent(ref: '#/components/schemas/CraftingReceipt'))]
    #[OA\Response(response: 400, ref: '#/components/responses/BadRequest')]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 409, ref: '#/components/responses/Conflict')]
    #[OA\Response(response: 422, description: 'recipe-unavailable, insufficient-crafting-resources ou validation.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/ProblemDetails')))]
    public function craft(#[CurrentUser] UserInterface $user, #[MapRequestPayload] CraftItemRequest $request, Request $httpRequest): JsonResponse
    {
        return new JsonResponse($this->crafting->craft(Uuid::fromString($user->getUserIdentifier()), $request->recipeKey, trim($httpRequest->headers->get('Idempotency-Key', ''))), 201);
    }
}
