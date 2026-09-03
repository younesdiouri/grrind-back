<?php

declare(strict_types=1);

namespace App\Training\UI\Http;

use App\Training\Domain\WorkoutRules;
use App\Training\Infrastructure\Doctrine\WorkoutRepository;
use App\Training\UI\Http\Response\SyncStateResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * L'état de synchronisation d'un joueur : jusqu'où le serveur connaît son historique, et
 * jusqu'où il accepte d'en créditer.
 *
 * Deux lectures et rien d'autre — pas de commande ni de handler : il n'y a aucune décision à
 * prendre ici, seulement un maximum en base et un réglage d'équilibrage. Un handler qui
 * enveloppe deux accès ajoute une indirection à lire et rien à comprendre.
 *
 * @see SyncStateResource pour le raisonnement produit derrière ces deux champs
 */
final readonly class SyncStateController
{
    public function __construct(
        private WorkoutRepository $workouts,
        private WorkoutRules $rules,
    ) {
    }

    #[Route('/api/workouts/sync-state', name: 'training_workout_sync_state', methods: ['GET'])]
    #[OA\Tag(name: 'Entraînement')]
    #[OA\Response(
        response: 200,
        description: 'De quoi demander la bonne fenêtre au fournisseur santé.',
        content: new OA\JsonContent(ref: '#/components/schemas/SyncState'),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    public function __invoke(
        #[CurrentUser]
        UserInterface $user,
    ): JsonResponse {
        $userId = Uuid::fromString($user->getUserIdentifier());

        return new JsonResponse(new SyncStateResource(
            $this->workouts->lastImportedAt($userId),
            $this->rules->importWindowDays(),
        )->toArray());
    }
}
