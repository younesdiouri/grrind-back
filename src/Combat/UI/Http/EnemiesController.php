<?php

declare(strict_types=1);

namespace App\Combat\UI\Http;

use App\Combat\Application\FighterFactory;
use App\Combat\Domain\EnemyCatalog;
use App\Combat\Infrastructure\Translation\EnemyTranslator;
use App\Combat\UI\Http\Response\EnemyCatalogResource;
use App\Shared\Application\PlayerProgressions;
use OpenApi\Attributes as OA;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Le catalogue des adversaires PvE, boss compris — ce dont un écran de sélection a besoin
 * pour se dessiner (#219).
 *
 * **Rend le catalogue entier, pas ce que l'appelant peut déjà affronter.** `minimumLevel`
 * n'aurait rien à faire dans le payload sinon, et le client ne pourrait pas griser ce qui
 * reste verrouillé — il ne pourrait que le taire.
 *
 * **`GET /api/enemies` et non `GET /api/battles/enemies`.** Le second entrerait en
 * collision avec `GET /api/battles/{id}` : `enemies` n'est pas un UUID, donc il tomberait
 * dans {@see VisibleBattleResolver}, qui rendrait un 404 avant même que la bonne route soit
 * essayée. Une route de premier niveau, à côté de `/api/titles` et `/api/workouts`, n'a pas
 * le problème.
 *
 * **`player` a besoin de `#[CurrentUser]` depuis le #227 — le catalogue lui-même n'en a
 * toujours pas besoin.** {@see EnemyCatalog} ne dépend pas de l'appelant ; seul le
 * combattant qu'{@see FighterFactory} dérive à côté en dépend. C'est le seul endroit de
 * l'API où l'effet des objets équipés se lit **avant** de s'engager dans un combat — voir
 * le docblock d'{@see \App\Combat\Application\FightBattleHandler} pour le pipeline complet
 * de cette dérivation, et le docblock d'{@see EnemyCatalogResource} pour pourquoi `player`
 * précède `enemies`. `GET /api/progression` continue de rendre le socle nu du ledger, sans
 * les modificateurs équipés : c'est délibéré, voir son docblock.
 */
final readonly class EnemiesController
{
    public function __construct(
        private EnemyCatalog $enemies,
        private EnemyTranslator $enemyNames,
        private PlayerProgressions $progressions,
        private FighterFactory $fighters,
        private ClockInterface $clock,
    ) {
    }

    #[Route('/api/enemies', name: 'combat_enemies_list', methods: ['GET'])]
    #[OA\Tag(name: 'Combat')]
    #[OA\Response(
        response: 200,
        description: 'Le combattant de l\'appelant, puis le catalogue entier — ennemis ordinaires et boss, dans cet ordre, chacun avec son nom traduit et son niveau minimum.',
        content: new OA\JsonContent(ref: '#/components/schemas/EnemyCatalog'),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    public function __invoke(
        #[CurrentUser]
        UserInterface $user,
    ): JsonResponse {
        $playerId = Uuid::fromString($user->getUserIdentifier());
        $now = $this->clock->now();

        $progressions = $this->progressions->of([$playerId]);
        $progression = $progressions[$playerId->toRfc4122()];

        $player = $this->fighters->forPlayer($progression, $playerId, $now);

        return new JsonResponse(EnemyCatalogResource::from($this->enemies, $this->enemyNames, $player)->toArray());
    }
}
