<?php

declare(strict_types=1);

namespace App\Training\UI\Http;

use App\Training\Application\StartSession;
use App\Training\Application\StartSessionHandler;
use App\Training\UI\Http\Request\StartSessionRequest;
use App\Training\UI\Http\Response\TrainingSessionResource;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Le joueur appuie sur « démarrer » ; le serveur ouvre la séance et la date.
 *
 * L'auteur vient de `#[CurrentUser]`, donc du jeton : aucune route ne prend
 * d'identifiant de compte en paramètre, donc aucune ne peut être détournée.
 *
 * L'argument est typé `UserInterface` et non `User` : Deptrac interdit à `Training`
 * d'importer une entité d'`Identity`, et l'identifiant de sécurité étant l'UUID du
 * compte, `getUserIdentifier()` suffit. Les autres contrôleurs des modules de jeu
 * répètent cette ligne ; un value resolver `#[CurrentPlayer]` la mutualisera quand il y
 * aura assez d'appelants — #46.
 */
final readonly class StartSessionController
{
    public function __construct(private StartSessionHandler $startSession)
    {
    }

    #[Route('/api/training/sessions', name: 'training_session_start', methods: ['POST'])]
    public function __invoke(
        #[CurrentUser]
        UserInterface $user,
        #[MapRequestPayload]
        StartSessionRequest $request,
    ): JsonResponse {
        $session = ($this->startSession)(new StartSession(
            Uuid::fromString($user->getUserIdentifier()),
            $request->discipline,
        ));

        return new JsonResponse(TrainingSessionResource::from($session)->toArray(), Response::HTTP_CREATED);
    }
}
