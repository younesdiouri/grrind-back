<?php

declare(strict_types=1);

namespace App\Training\UI\Http;

use App\Shared\UI\Http\Idempotent;
use App\Training\Application\AbandonSession;
use App\Training\Application\AbandonSessionHandler;
use App\Training\UI\Http\Response\TrainingSessionResource;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Le joueur renonce. La séance est close, ne rapporte rien, et reste dans l'historique :
 * un abandon est une information, pas une erreur à effacer.
 *
 * `#[Idempotent]` comme la clôture, bien que rien ne se double ici : sans la clé, le rejeu
 * d'une requête déjà passée rendrait un `409` au client qui, lui, n'a fait que renvoyer une
 * requête perdue en route. Il afficherait un échec pour une action réussie. La clé fait de
 * ce cas ce qu'il est — une non-opération — et le `409` retrouve son sens : *une autre*
 * requête a fermé cette séance entre-temps.
 *
 * Ni le motif ni la durée ne se déclarent : le corps de la requête n'est pas lu.
 */
final readonly class AbandonSessionController
{
    public function __construct(private AbandonSessionHandler $abandonSession)
    {
    }

    #[Route('/api/training/sessions/{id}/abandon', name: 'training_session_abandon', methods: ['POST'])]
    #[Idempotent]
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
