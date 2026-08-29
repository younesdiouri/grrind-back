<?php

declare(strict_types=1);

namespace App\Combat\UI\Http;

use App\Combat\Domain\Battle;
use App\Combat\Domain\EnemyCatalog;
use App\Combat\Infrastructure\Translation\EnemyTranslator;
use App\Combat\UI\Http\Response\BattleResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le rejeu d'un combat déjà joué — exactement la charge utile que `POST /api/battles` a
 * rendue, pour qu'un client qui a perdu l'animation en cours de route puisse la relancer.
 */
final readonly class ShowBattleController
{
    public function __construct(
        private EnemyCatalog $enemies,
        private EnemyTranslator $enemyNames,
    ) {
    }

    #[Route('/api/battles/{id}', name: 'combat_battle_show', methods: ['GET'])]
    #[OA\Tag(name: 'Combat')]
    #[OA\Response(
        response: 200,
        description: 'Exactement la charge utile rendue par `POST /api/battles` au moment du combat.',
        content: new OA\JsonContent(ref: '#/components/schemas/Battle'),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(
        response: 404,
        description: <<<'TXT'
            Le combat n'existe pas, **ou n'a pas été mené par l'appelant** (`battle-not-found`).
            Les deux cas rendent la même réponse, et **jamais 403** : un 403 confirmerait qu'un
            combat porte cet UUID, et les UUID v7 encodent leur instant de création — l'API
            deviendrait un moyen d'énumérer les combats joués un jour donné.
            TXT,
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/ProblemDetails'),
        ),
    )]
    public function __invoke(
        #[VisibleBattle]
        Battle $battle,
    ): JsonResponse {
        return new JsonResponse(BattleResource::from($battle, $this->enemies, $this->enemyNames)->toArray());
    }
}
