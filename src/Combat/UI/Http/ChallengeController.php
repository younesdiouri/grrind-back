<?php

declare(strict_types=1);

namespace App\Combat\UI\Http;

use App\Combat\Application\ChallengePlayer;
use App\Combat\Application\ChallengePlayerHandler;
use App\Combat\Domain\Exception\OpponentNotFound;
use App\Combat\Infrastructure\Translation\EnemyTranslator;
use App\Combat\UI\Http\Response\BattleResource;
use App\Shared\Application\ItemImageUrlResolver;
use App\Shared\UI\Http\Idempotent;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * La porte du défi PvP (#283) : on nomme un co-équipier, le serveur joue le combat et rend
 * **exactement** la charge utile de `POST /api/battles` — timeline comprise, en un seul
 * aller-retour, pour que le client réutilise l'écran d'animation qu'il a déjà écrit.
 *
 * **La route vit dans `Combat` et non dans `Community`** : ce qu'elle produit est un
 * `Battle`, et c'est ce module qui le possède. Elle est sous `/api/players/{id}` parce que
 * c'est le geste du produit — on ouvre le profil d'un co-équipier et on le défie — et non
 * sous `/api/guilds/…` : le jour où le PvP s'ouvrira à un adversaire de ligue, seule la
 * clause du port {@see \App\Shared\Application\Teammates} changera, pas l'URL.
 *
 * **Pas de corps, pas de `MapRequestPayload`.** Tout ce qu'un défi a besoin de savoir tient
 * dans le jeton et le `{id}` ; un corps ne pourrait apporter que des valeurs de jeu, et
 * aucune valeur de jeu ne vient du client.
 *
 * **`#[Idempotent]`, pour la raison exacte de `POST /api/battles`.** Un combat est un tirage
 * aléatoire sans unicité naturelle : sans clé, un rejeu réseau écrirait un *second* défi
 * contre le même joueur, avec une autre issue, et le client animerait celui qu'il n'a pas
 * enregistré.
 *
 * **Le `{id}` n'est pas résolu par un value resolver.** `VisiblePlayer` vit dans `Community`
 * et Deptrac interdit de l'importer ici ; surtout, l'autorisation d'un défi n'est pas celle
 * d'un regard — voir le docblock de {@see ChallengePlayerHandler}, qui la porte entière,
 * refus compris.
 */
final readonly class ChallengeController
{
    public function __construct(
        private ChallengePlayerHandler $challenge,
        private EnemyTranslator $enemyNames,
        private ItemImageUrlResolver $items,
    ) {
    }

    #[Route('/api/players/{id}/battles', name: 'combat_battle_challenge', methods: ['POST'])]
    #[Idempotent]
    #[OA\Tag(name: 'Combat')]
    #[OA\Parameter(ref: '#/components/parameters/IdempotencyKey')]
    #[OA\Response(
        response: 201,
        description: 'Le défi est joué et écrit. Exactement la charge utile de `POST /api/battles` : `enemy.key` vaut `null`, `enemy.playerId` porte le défié, `enemy.name` son pseudo au moment du combat.',
        content: new OA\JsonContent(ref: '#/components/schemas/Battle'),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(
        response: 404,
        description: <<<'TXT'
            Ce joueur n'existe pas, **ou n'est pas un co-équipier** (`opponent-not-found`).
            Les deux cas rendent la même réponse, et **jamais 403** : un 403 confirmerait qu'un
            compte porte cet UUID, et les UUID v7 encodent leur instant de création — l'API
            deviendrait un moyen d'énumérer les comptes ouverts un jour donné.
            TXT,
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/ProblemDetails'),
        ),
    )]
    #[OA\Response(response: 409, ref: '#/components/responses/Conflict')]
    #[OA\Response(
        response: 422,
        description: 'On ne se défie pas soi-même (`cannot-challenge-yourself`).',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/ProblemDetails'),
        ),
    )]
    public function __invoke(
        #[CurrentUser]
        UserInterface $user,
        string $id,
    ): JsonResponse {
        // Un `{id}` qui n'est pas un UUID n'a jamais désigné un joueur : le même 404 qu'un
        // inconnu, plutôt qu'un 400 qui renseignerait sur la forme attendue.
        if (!Uuid::isValid($id)) {
            throw new OpponentNotFound();
        }

        $battle = ($this->challenge)(new ChallengePlayer(
            Uuid::fromString($user->getUserIdentifier()),
            Uuid::fromString($id),
        ));

        return new JsonResponse(
            BattleResource::from($battle, $this->enemyNames, $this->items)->toArray(),
            Response::HTTP_CREATED,
        );
    }
}
