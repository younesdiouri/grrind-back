<?php

declare(strict_types=1);

namespace App\Training\UI\Http;

use App\Training\Infrastructure\Doctrine\WorkoutRepository;
use App\Training\UI\Http\Response\TrainingSessionResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * « Est-ce que j'ai un chrono qui tourne ? » — la question posée au démarrage de l'app.
 *
 * **204 et non 404** quand rien ne tourne : n'avoir aucune séance en cours est l'état
 * normal du joueur. Un 404 le ferait traiter dans la branche d'échec du client, où il
 * se confondrait avec le vrai 404 des routes voisines.
 *
 * Pas de handler : ni règle, ni page à découper, ni écriture à ordonner.
 */
final readonly class ActiveSessionController
{
    public function __construct(private WorkoutRepository $sessions)
    {
    }

    #[Route('/api/training/sessions/active', name: 'training_session_active', methods: ['GET'])]
    #[OA\Tag(name: 'Entraînement')]
    #[OA\Response(
        response: 200,
        description: 'La séance en cours.',
        content: new OA\JsonContent(ref: '#/components/schemas/TrainingSession'),
    )]
    #[OA\Response(response: 204, description: 'Aucune séance en cours — l\'état normal du joueur, pas une erreur.')]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    public function __invoke(
        #[CurrentUser]
        UserInterface $user,
    ): Response {
        $session = $this->sessions->activeOf(Uuid::fromString($user->getUserIdentifier()));

        if (null === $session) {
            return new Response(status: Response::HTTP_NO_CONTENT);
        }

        return new JsonResponse(TrainingSessionResource::from($session)->toArray());
    }
}
