<?php

declare(strict_types=1);

namespace App\Community\UI\Http;

use App\Community\Application\AlamRuns;
use App\Community\Domain\Exception\AlamRunNotFound;
use App\Community\UI\Http\Request\AlamHistoryQuery;
use App\Shared\UI\Http\Cursor;
use App\Shared\UI\Http\Idempotent;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

#[OA\Tag(name: 'Alam')]
final readonly class AlamController
{
    public function __construct(private AlamRuns $runs)
    {
    }

    #[Route('/api/guild/alam', name: 'alam_current', methods: ['GET'])]
    #[OA\Response(response: 200, description: 'Semaine collective et capacité de lancement manuel.', content: new OA\JsonContent(ref: '#/components/schemas/AlamCurrent'))]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    public function current(#[CurrentUser] UserInterface $user): JsonResponse
    {
        return new JsonResponse($this->runs->current(Uuid::fromString($user->getUserIdentifier())));
    }

    #[Route('/api/guild/alam/runs', name: 'alam_manual', methods: ['POST'])]
    #[Idempotent]
    #[OA\Parameter(ref: '#/components/parameters/IdempotencyKey')]
    #[OA\Response(response: 201, description: 'Édition manuelle distincte, résultats et récompenses figés.', content: new OA\JsonContent(ref: '#/components/schemas/AlamRun'))]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 403, ref: '#/components/responses/Forbidden')]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    #[OA\Response(response: 409, ref: '#/components/responses/Conflict')]
    public function manual(#[CurrentUser] UserInterface $user, Request $request): JsonResponse
    {
        return new JsonResponse($this->runs->resource($this->runs->manual(Uuid::fromString($user->getUserIdentifier()), $request->headers->get('Idempotency-Key', ''))), 201);
    }

    #[Route('/api/guild/alam/runs', name: 'alam_history', methods: ['GET'])]
    #[OA\Response(response: 200, description: 'Historique des éditions de la guilde.', content: new OA\JsonContent(ref: '#/components/schemas/AlamPage'))]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    public function history(#[CurrentUser] UserInterface $user, #[MapQueryString] AlamHistoryQuery $query = new AlamHistoryQuery()): JsonResponse
    {
        return new JsonResponse($this->runs->history(Uuid::fromString($user->getUserIdentifier()), $query->limit, Cursor::fromQuery($query, $query->cursor)));
    }

    #[Route('/api/guild/alam/runs/{id}', name: 'alam_show', methods: ['GET'])]
    #[OA\Response(response: 200, description: 'Journal durable live/replay ; lecture réservée aux participants et membres actuels.', content: new OA\JsonContent(ref: '#/components/schemas/AlamRun'))]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    public function show(string $id, #[CurrentUser] UserInterface $user): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            throw new AlamRunNotFound();
        }

        return new JsonResponse($this->runs->resource($this->runs->show(Uuid::fromString($id), Uuid::fromString($user->getUserIdentifier()))));
    }
}
