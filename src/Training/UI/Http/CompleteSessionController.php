<?php

declare(strict_types=1);

namespace App\Training\UI\Http;

use App\Shared\UI\Http\Idempotent;
use App\Training\Application\CompleteSession;
use App\Training\Application\CompleteSessionHandler;
use App\Training\UI\Http\Response\TrainingSessionResource;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Le joueur appuie sur « terminer » ; le serveur date la fin et fige la durée.
 *
 * `#[Idempotent]` n'est pas une précaution : c'est la première écriture métier du
 * produit, et un client mobile rejoue. Au Lot 4 cette requête accordera de l'XP et
 * tirera du loot — une seconde exécution doublerait les gains. L'en-tête est donc
 * obligatoire dès maintenant, pendant que la réponse est encore anodine, plutôt qu'à
 * l'instant où il deviendra coûteux de l'imposer à un client déjà déployé.
 *
 * L'identifiant de la séance est dans l'URL, celui du compte n'y est pas : il vient de
 * `#[CurrentUser]`. C'est ce qui rend la route inoffensive à énumérer — l'identifiant
 * d'autrui ne mène qu'à un 404, cf. {@see \App\Training\Domain\Exception\SessionNotFound}.
 *
 * Le `Uuid` en paramètre est résolu par l'`UidValueResolver` du framework, qui rend déjà
 * un 404 sur une chaîne malformée : un identifiant illisible et un identifiant inconnu
 * sont le même non-résultat, il n'y a rien à écrire de plus.
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
        $session = ($this->completeSession)(new CompleteSession(
            Uuid::fromString($user->getUserIdentifier()),
            $id,
        ));

        return new JsonResponse(TrainingSessionResource::from($session)->toArray());
    }
}
