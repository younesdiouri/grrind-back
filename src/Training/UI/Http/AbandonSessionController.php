<?php

declare(strict_types=1);

namespace App\Training\UI\Http;

use App\Shared\UI\Http\Idempotent;
use App\Training\Application\AbandonSession;
use App\Training\Application\AbandonSessionHandler;
use App\Training\UI\Http\Response\TrainingSessionResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Le joueur renonce. La séance est close, ne rapporte rien, et reste dans l'historique :
 * un abandon est une information, pas une erreur à effacer.
 *
 * `#[Idempotent]` bien que rien ne se double ici. Le doublon ne coûte rien au jeu, il
 * coûte au client : sans la clé, une requête perdue puis renvoyée rendrait un `409` —
 * un échec affiché pour une action réussie. La clé en fait la non-opération qu'elle est,
 * et rend au `409` son seul sens utile : *une autre* requête a fermé cette séance.
 */
final readonly class AbandonSessionController
{
    public function __construct(private AbandonSessionHandler $abandonSession)
    {
    }

    #[Route('/api/training/sessions/{id}/abandon', name: 'training_session_abandon', methods: ['POST'])]
    #[Idempotent]
    #[OA\Tag(name: 'Entraînement')]
    #[OA\Parameter(ref: '#/components/parameters/IdempotencyKey')]
    #[OA\RequestBody(required: false, description: 'Aucun corps attendu.')]
    #[OA\Response(
        response: 200,
        description: 'La séance est close et ne rapporte rien. Elle reste dans l\'historique.',
        content: new OA\JsonContent(ref: '#/components/schemas/TrainingSession'),
    )]
    #[OA\Response(response: 400, ref: '#/components/responses/BadRequest')]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    #[OA\Response(response: 409, ref: '#/components/responses/Conflict')]
    public function __invoke(
        #[CurrentUser]
        UserInterface $user,
        Uuid $id,
    ): JsonResponse {
        $session = ($this->abandonSession)(new AbandonSession(
            Uuid::fromString($user->getUserIdentifier()),
            $id,
        ));

        return new JsonResponse(TrainingSessionResource::from($session)->toArray());
    }
}
