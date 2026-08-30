<?php

declare(strict_types=1);

namespace App\Rewards\UI\Http;

use App\Rewards\Application\EquipItem;
use App\Rewards\Application\EquipItemHandler;
use App\Rewards\Application\InventoryOverviewProvider;
use App\Rewards\Application\UnequipItem;
use App\Rewards\Application\UnequipItemHandler;
use App\Rewards\Domain\ItemCatalog;
use App\Rewards\Infrastructure\Translation\ItemTranslator;
use App\Rewards\UI\Http\Request\EquipItemRequest;
use App\Rewards\UI\Http\Response\InventoryResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Le sac, la doublure équipée, la bourse — et les deux gestes qui font vivre l'équipement
 * (#30). Aucun identifiant de compte sur aucune route : le joueur vient de `#[CurrentUser]`,
 * comme `/api/me`, `/api/progression` et `/api/workouts` — un inventaire n'est pas une
 * donnée de co-équipier, la question du voter et du « 404 jamais 403 » ne se pose pas ici.
 *
 * **`{slot}` est une chaîne brute, jamais l'enum {@see \App\Rewards\Domain\EquipmentSlot}
 * résolue par Symfony.** Un typage direct aurait fait résoudre — ou refuser en 404 — la
 * valeur avant que le contrôleur ne la voie, exactement comme `{provider}` sur
 * `POST /api/auth/social/{provider}`. Un emplacement inconnu est ici une **règle de jeu**,
 * pas une ressource absente : {@see \App\Rewards\Domain\Exception\EquipmentSlotUnknown} doit
 * pouvoir la nommer dans un 422, jamais un 404 muet — voir le docblock d'{@see EquipItem}
 * pour cette même décision, prise au #29 avant que la route n'existe.
 *
 * **`PUT` et `DELETE` rendent tous deux l'inventaire entier, comme `GET`.** Même geste que
 * `PUT /api/titles/active` : équiper ou déséquiper change un écran que le joueur regarde
 * dans la foulée — le sac, la doublure — et le lui rendre en un seul aller-retour évite un
 * second `GET` après chaque mutation. `DailyActivityController` et `GuildInviteController`
 * choisissent un 204 pour la raison inverse : ce qu'ils font disparaître ne laisse rien
 * d'utile à afficher juste après.
 *
 * **`PUT` échange, il ne refuse jamais un emplacement occupé — et il est idempotent.** Voir
 * le docblock d'{@see \App\Rewards\Infrastructure\Doctrine\InventoryItemRepository::equip()}.
 * **`DELETE` est idempotent aussi** : vider un emplacement déjà vide n'est pas une erreur,
 * voir {@see \App\Rewards\Infrastructure\Doctrine\InventoryItemRepository::unequip()}.
 */
final readonly class InventoryController
{
    public function __construct(
        private InventoryOverviewProvider $overview,
        private EquipItemHandler $equipItem,
        private UnequipItemHandler $unequipItem,
        private ItemCatalog $catalog,
        private ItemTranslator $translator,
    ) {
    }

    #[Route('/api/inventory', name: 'rewards_inventory_show', methods: ['GET'])]
    #[OA\Tag(name: 'Récompenses')]
    #[OA\Response(
        response: 200,
        description: 'Le sac, la doublure équipée par emplacement, et le solde de pièces.',
        content: new OA\JsonContent(ref: '#/components/schemas/Inventory'),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    public function index(#[CurrentUser] UserInterface $user): JsonResponse
    {
        return $this->overviewResponse($user);
    }

    #[Route('/api/inventory/equipment/{slot}', name: 'rewards_inventory_equip', methods: ['PUT'])]
    #[OA\Tag(name: 'Récompenses')]
    #[OA\Response(
        response: 200,
        description: 'L\'inventaire, après échange : l\'objet est en place, l\'ancien occupant de l\'emplacement — s\'il y en avait un — est retourné au sac.',
        content: new OA\JsonContent(ref: '#/components/schemas/Inventory'),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(
        response: 422,
        description: 'Emplacement inconnu (`equipment-slot-unknown`), objet non possédé (`item-not-owned`), ou objet incompatible avec l\'emplacement demandé (`equipment-slot-incompatible`).',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/ProblemDetails'),
        ),
    )]
    public function equip(
        #[CurrentUser]
        UserInterface $user,
        string $slot,
        #[MapRequestPayload]
        EquipItemRequest $request,
    ): JsonResponse {
        ($this->equipItem)(new EquipItem(Uuid::fromString($user->getUserIdentifier()), $request->itemKey, $slot));

        return $this->overviewResponse($user);
    }

    #[Route('/api/inventory/equipment/{slot}', name: 'rewards_inventory_unequip', methods: ['DELETE'])]
    #[OA\Tag(name: 'Récompenses')]
    #[OA\Response(
        response: 200,
        description: 'L\'inventaire, l\'emplacement vidé. Le vider alors qu\'il l\'était déjà rend la même réponse.',
        content: new OA\JsonContent(ref: '#/components/schemas/Inventory'),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(
        response: 422,
        description: 'Emplacement inconnu (`equipment-slot-unknown`).',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/ProblemDetails'),
        ),
    )]
    public function unequip(
        #[CurrentUser]
        UserInterface $user,
        string $slot,
    ): JsonResponse {
        ($this->unequipItem)(new UnequipItem(Uuid::fromString($user->getUserIdentifier()), $slot));

        return $this->overviewResponse($user);
    }

    private function overviewResponse(UserInterface $user): JsonResponse
    {
        $overview = $this->overview->of(Uuid::fromString($user->getUserIdentifier()));

        return new JsonResponse(InventoryResource::from($overview, $this->catalog, $this->translator)->toArray());
    }
}
