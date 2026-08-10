<?php

declare(strict_types=1);

namespace App\Training\UI\Http;

use App\Training\Application\ListSessions;
use App\Training\Application\ListSessionsHandler;
use App\Training\UI\Http\Request\SessionHistoryQuery;
use App\Training\UI\Http\Response\SessionPageResource;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * L'historique du joueur, du plus récent au plus ancien.
 *
 * Le compte n'est pas un paramètre : il vient du jeton. C'est ce qui rend l'isolation
 * structurelle plutôt que déclarative — il n'existe aucune requête capable de demander
 * l'historique d'un autre, donc aucune à refuser.
 *
 * L'argument `#[MapQueryString]` n'est ni nullable ni pourvu d'un défaut, et c'est
 * voulu : le résolveur ne construit le DTO sur une query string vide que dans ce
 * cas-là. Nullable, une requête sans paramètre rendrait `null` et il faudrait un
 * `?? new SessionHistoryQuery()` à la main — les valeurs par défaut sont déjà dans le
 * constructeur, elles n'ont pas à être écrites deux fois.
 */
final readonly class ListSessionsController
{
    public function __construct(private ListSessionsHandler $listSessions)
    {
    }

    #[Route('/api/training/sessions', name: 'training_session_list', methods: ['GET'])]
    public function __invoke(
        #[CurrentUser]
        UserInterface $user,
        #[MapQueryString]
        SessionHistoryQuery $query,
    ): JsonResponse {
        $page = ($this->listSessions)(new ListSessions(
            Uuid::fromString($user->getUserIdentifier()),
            $query->status,
            $query->discipline,
            $query->from,
            $query->to,
            $query->cursor,
            $query->limit,
        ));

        return new JsonResponse(SessionPageResource::from($page)->toArray());
    }
}
