<?php

declare(strict_types=1);

namespace App\Combat\UI\Http;

use App\Combat\Application\FightBattle;
use App\Combat\Application\FightBattleHandler;
use App\Combat\Infrastructure\Translation\EnemyTranslator;
use App\Combat\UI\Http\Request\FightBattleRequest;
use App\Combat\UI\Http\Response\BattleResource;
use App\Shared\Application\ItemImageUrlResolver;
use App\Shared\UI\Http\Idempotent;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * La porte d'entrée du combat PvE : par défaut le serveur choisit l'ennemi, joue le combat,
 * rend la timeline entière.
 *
 * **Le corps est facultatif (#219), et son absence ne change rien au comportement du
 * #212.** `{"enemy": "DUNE_SOVEREIGN"}` nomme l'adversaire — boss ou ennemi ordinaire, voir
 * le docblock de {@see FightBattle} — mais un client qui n'envoie rien continue de recevoir
 * l'ennemi choisi au niveau du joueur, sans un octet à ajouter à sa requête actuelle.
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
        private ItemImageUrlResolver $items,
    ) {
    }

    #[Route('/api/battles', name: 'combat_battle_fight', methods: ['POST'])]
    #[Idempotent]
    #[OA\Tag(name: 'Combat')]
    #[OA\Parameter(ref: '#/components/parameters/IdempotencyKey')]
    #[OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/ChosenEnemyRequest'))]
    #[OA\Response(
        response: 201,
        description: 'Le combat est joué et écrit. La timeline entière est rendue : un seul aller-retour, rien à recharger avant de l\'animer.',
        content: new OA\JsonContent(ref: '#/components/schemas/Battle'),
    )]
    #[OA\Response(response: 400, ref: '#/components/responses/BadRequest')]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 409, ref: '#/components/responses/Conflict')]
    #[OA\Response(
        response: 422,
        description: 'Clé d\'adversaire inconnue (`enemy-key-unknown`) ou niveau insuffisant (`enemy-level-too-low`).',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/ProblemDetails'),
        ),
    )]
    public function __invoke(
        #[CurrentUser]
        UserInterface $user,
        #[MapRequestPayload]
        ?FightBattleRequest $request = null,
    ): JsonResponse {
        $battle = ($this->fight)(new FightBattle(Uuid::fromString($user->getUserIdentifier()), $request?->enemy));

        return new JsonResponse(
            BattleResource::from($battle, $this->enemyNames, $this->items)->toArray(),
            Response::HTTP_CREATED,
        );
    }
}
