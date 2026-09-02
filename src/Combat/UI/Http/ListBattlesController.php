<?php

declare(strict_types=1);

namespace App\Combat\UI\Http;

use App\Combat\Application\ListBattles;
use App\Combat\Application\ListBattlesHandler;
use App\Combat\Infrastructure\Translation\EnemyTranslator;
use App\Combat\UI\Http\Request\BattleHistoryQuery;
use App\Combat\UI\Http\Response\BattlePageResource;
use App\Shared\Application\ItemImageUrlResolver;
use App\Shared\UI\Http\Cursor;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * L'historique de ses combats, du plus récent au plus ancien (#220). Le #212 a livré de quoi
 * jouer un combat et le rejouer précisément ; rien ne permettait de retrouver lequel — ce
 * ticket branche sur ce que le #211 (la ligne) et le #218/#219 (le tri, la pagination
 * partagée) ont déjà posé.
 *
 * **La liste ne porte jamais les timelines.** Un `Battle` complet peut compter deux cents
 * événements — `max_turns` vaut 200, et chaque tour en produit un ou deux. Vingt combats par
 * page, et la réponse deviendrait un mégaoctet de choses que personne ne regarde : on ne
 * rejoue pas vingt combats à la fois, on en choisit un. Chaque ligne est donc un
 * {@see Response\BattleSummaryResource}, et le client va chercher la
 * timeline entière par le `GET /api/battles/{id}` qui existe déjà — un aller-retour de plus au
 * moment où le joueur choisit, contre une liste qui reste légère à chaque chargement.
 *
 * **Aucun filtre.** `WorkoutHistoryQuery` en porte trois parce que trois écrans les
 * demandaient ; ici aucun écran ne les réclame encore, et un paramètre ajouté « pendant qu'on
 * y est » est un paramètre à tester et à documenter pour toujours. Même raisonnement pour
 * l'absence de statistiques agrégées (victoires, série en cours, adversaire le plus battu) :
 * un autre écran, un autre calcul, probablement un autre ticket.
 *
 * Le curseur et la forme de la page sont ceux de `Shared` ({@see Cursor}) — le pendant exact
 * de {@see \App\Training\UI\Http\ListWorkoutsController} — précisément pour que cet historique
 * et celui des workouts ne divergent pas côté client.
 */
final readonly class ListBattlesController
{
    public function __construct(
        private ListBattlesHandler $listBattles,
        private EnemyTranslator $enemyNames,
        private ItemImageUrlResolver $items,
    ) {
    }

    #[Route('/api/battles', name: 'combat_battle_list', methods: ['GET'])]
    #[OA\Tag(name: 'Combat')]
    #[OA\Response(
        response: 200,
        description: 'Une page d\'historique, du plus récent au plus ancien — un résumé par combat, jamais sa timeline.',
        content: new OA\JsonContent(ref: '#/components/schemas/BattlePage'),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntity')]
    public function __invoke(
        #[CurrentUser]
        UserInterface $user,
        #[MapQueryString]
        BattleHistoryQuery $query,
    ): JsonResponse {
        $page = ($this->listBattles)(new ListBattles(
            Uuid::fromString($user->getUserIdentifier()),
            Cursor::fromQuery($query, $query->cursor),
            $query->limit,
        ));

        return new JsonResponse(BattlePageResource::from($page, $this->enemyNames, $this->items)->toArray());
    }
}
