<?php

declare(strict_types=1);

namespace App\Training\UI\Http;

use App\Shared\UI\Http\Idempotent;
use App\Training\Application\CompleteSession;
use App\Training\Application\CompleteSessionHandler;
use App\Training\UI\Http\Response\RewardSummaryResource;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Le joueur appuie sur « terminer » ; le serveur date la fin, fige la durée, crédite l'XP
 * et rend de quoi jouer l'animation — le tout en une transaction et un aller-retour.
 *
 * `#[Idempotent]` était déjà imposé quand la réponse était anodine, précisément pour ce
 * moment : un client mobile rejoue ses requêtes, et cette route accorde désormais de l'XP.
 *
 * Le `Uuid` en paramètre est résolu par l'`UidValueResolver`, qui rend déjà un 404 sur
 * une chaîne malformée — illisible et inconnu sont le même non-résultat.
 */
final readonly class CompleteSessionController
{
    public function __construct(private CompleteSessionHandler $completeSession)
    {
    }

    #[Route('/api/training/sessions/{id}/complete', name: 'training_session_complete', methods: ['POST'])]
    #[Idempotent]
    public function __invoke(
        #[CurrentUser]
        UserInterface $user,
        Uuid $id,
    ): JsonResponse {
        $completion = ($this->completeSession)(new CompleteSession(
            Uuid::fromString($user->getUserIdentifier()),
            $id,
        ));

        return new JsonResponse(RewardSummaryResource::from($completion)->toArray());
    }
}
