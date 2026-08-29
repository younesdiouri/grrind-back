<?php

declare(strict_types=1);

namespace App\Combat\UI\Http;

use App\Combat\Application\FightBattle;
use App\Combat\Application\FightBattleHandler;
use App\Combat\Infrastructure\Translation\EnemyTranslator;
use App\Combat\UI\Http\Response\BattleResource;
use App\Shared\UI\Http\Idempotent;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * La porte d'entrée du combat PvE : le serveur choisit l'ennemi, joue le combat, rend la
 * timeline entière.
 *
 * **Aucun corps.** Le client ne choisit rien — ni l'adversaire, ni quoi que ce soit d'autre,
 * voir le docblock de {@see FightBattle} — donc il n'y a rien à valider ni à documenter côté
 * requête.
 *
 * **`#[Idempotent]`, et c'est le cas où ça compte le plus dans tout le produit.** Un rejeu
 * réseau doit rendre le combat déjà joué, jamais en tirer un second : un import rejouable
 * (#88) peut se refaire sans perte, puisque l'unicité `(user, source, externalId)` protège
 * le crédit — mais un combat n'a pas d'équivalent. C'est un tirage aléatoire, non
 * reproductible sans la graine, et sans la clé d'idempotence un client qui rejoue perdrait
 * la mise en scène de *ce* combat pour en recevoir un autre.
 */
final readonly class FightController
{
    public function __construct(
        private FightBattleHandler $fight,
        private EnemyTranslator $enemyNames,
    ) {
    }

    #[Route('/api/battles', name: 'combat_battle_fight', methods: ['POST'])]
    #[Idempotent]
    #[OA\Tag(name: 'Combat')]
    #[OA\Parameter(ref: '#/components/parameters/IdempotencyKey')]
    #[OA\Response(
        response: 201,
        description: 'Le combat est joué et écrit. La timeline entière est rendue : un seul aller-retour, rien à recharger avant de l\'animer.',
        content: new OA\JsonContent(ref: '#/components/schemas/Battle'),
    )]
    #[OA\Response(response: 400, ref: '#/components/responses/BadRequest')]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 409, ref: '#/components/responses/Conflict')]
    public function __invoke(
        #[CurrentUser]
        UserInterface $user,
    ): JsonResponse {
        $battle = ($this->fight)(new FightBattle(Uuid::fromString($user->getUserIdentifier())));

        return new JsonResponse(
            BattleResource::from($battle, $this->enemyNames)->toArray(),
            Response::HTTP_CREATED,
        );
    }
}
