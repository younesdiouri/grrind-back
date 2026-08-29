<?php

declare(strict_types=1);

namespace App\Combat\UI\Http;

use App\Combat\Domain\Enemy;
use App\Combat\Domain\EnemyCatalog;
use App\Combat\Infrastructure\Translation\EnemyTranslator;
use App\Combat\UI\Http\Response\EnemyResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le catalogue des adversaires PvE, boss compris — ce dont un écran de sélection a besoin
 * pour se dessiner (#219).
 *
 * **Rend le catalogue entier, pas ce que l'appelant peut déjà affronter.** `minimumLevel`
 * n'aurait rien à faire dans le payload sinon, et le client ne pourrait pas griser ce qui
 * reste verrouillé — il ne pourrait que le taire. La route est authentifiée — un appelant
 * anonyme est refusé, comme partout sous `^/api` — mais son contenu ne dépend pas du niveau
 * de l'appelant, donc aucun `#[CurrentUser]` n'entre ici : le firewall suffit à trancher le
 * seul cas qui compte, présent ou pas.
 *
 * **`GET /api/enemies` et non `GET /api/battles/enemies`.** Le second entrerait en
 * collision avec `GET /api/battles/{id}` : `enemies` n'est pas un UUID, donc il tomberait
 * dans {@see VisibleBattleResolver}, qui rendrait un 404 avant même que la bonne route soit
 * essayée. Une route de premier niveau, à côté de `/api/titles` et `/api/workouts`, n'a pas
 * le problème.
 */
final readonly class EnemiesController
{
    public function __construct(
        private EnemyCatalog $enemies,
        private EnemyTranslator $enemyNames,
    ) {
    }

    #[Route('/api/enemies', name: 'combat_enemies_list', methods: ['GET'])]
    #[OA\Tag(name: 'Combat')]
    #[OA\Response(
        response: 200,
        description: 'Le catalogue entier — ennemis ordinaires et boss, dans cet ordre, chacun avec son nom traduit et son niveau minimum.',
        content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/Enemy')),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    public function __invoke(): JsonResponse
    {
        $entries = array_map(
            fn (Enemy $enemy): array => EnemyResource::from($enemy, $this->enemyNames)->toArray(),
            [...$this->enemies->all(), ...$this->enemies->bosses()],
        );

        return new JsonResponse($entries);
    }
}
