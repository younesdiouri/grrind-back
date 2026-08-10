<?php

declare(strict_types=1);

namespace App\Training\UI\Http;

use App\Training\Infrastructure\Doctrine\TrainingSessionRepository;
use App\Training\UI\Http\Response\TrainingSessionResource;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * « Est-ce que j'ai un chrono qui tourne ? » — la question que l'app pose à son
 * démarrage, et à laquelle elle doit répondre avant d'afficher quoi que ce soit.
 *
 * Une route à part plutôt qu'un champ de l'historique : ce sont deux écrans, deux
 * fréquences et deux tailles de réponse. Adosser la séance active à la liste
 * obligerait à charger une page d'historique pour savoir si le chronomètre tourne,
 * et poserait la question de ce que devient ce champ quand la liste est filtrée.
 *
 * **204 et non 404** quand rien ne tourne. N'avoir aucune séance en cours est une
 * réponse, pas une erreur — c'est même l'état normal du joueur. Un 404 obligerait le
 * client à traiter un cas nominal dans sa branche d'échec, et se confondrait avec le
 * vrai 404 des routes voisines.
 *
 * Pas de handler d'application ici : il n'y a ni règle à appliquer, ni page à
 * découper, ni écriture à ordonner. Une classe qui se contenterait de relayer l'appel
 * au dépôt est exactement l'indirection que la règle n°0 demande de ne pas écrire.
 */
final readonly class ActiveSessionController
{
    public function __construct(private TrainingSessionRepository $sessions)
    {
    }

    #[Route('/api/training/sessions/active', name: 'training_session_active', methods: ['GET'])]
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
