<?php

declare(strict_types=1);

namespace App\Rewards\Application;

use App\Rewards\Domain\Exception\ItemAlreadyOwned;
use App\Rewards\Domain\InventoryItem;
use App\Rewards\Domain\Item;
use App\Rewards\Domain\ItemKind;
use App\Rewards\Infrastructure\Doctrine\InventoryItemRepository;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * L'unique porte d'écriture de l'inventaire d'un joueur — même geste et même raison que
 * {@see CoinLedger} pour les pièces. **Personne en dehors de
 * `Rewards` n'y crédite d'objet** : le #226 (import) et le #227 (combat) traverseront cette
 * classe chacun par **son** port, défini dans son propre module — ce n'est pas à ce ticket
 * (#29) de deviner leur forme.
 *
 * Le choix de l'objet crédité n'a pas sa place ici : il vient déjà décidé de
 * {@see \App\Rewards\Domain\LootRoller}. Cette classe ne fait qu'écrire, sous la garantie que
 * porte {@see InventoryItemRepository::grant()}.
 */
final readonly class Inventory
{
    public function __construct(
        private InventoryItemRepository $items,
    ) {
    }

    /**
     * Crédite un exemplaire de `$itemKey`, obtenu par le tirage `$lootRollId`.
     *
     * `$obtainedAt` est la date **du fait qui a produit le tirage** — celle du sport pour un
     * drop de séance, l'instant du combat pour un drop de combat — jamais celle de l'appel,
     * même règle que partout ailleurs sur ce module.
     *
     * Si le joueur possède déjà `$itemKey`, `$lootRollId` et `$obtainedAt` n'écrasent rien :
     * voir le docblock d'{@see InventoryItem} pour pourquoi la provenance
     * reste celle de la toute première acquisition.
     */
    public function grant(Uuid $userId, string $itemKey, Uuid $lootRollId, DateTimeImmutable $obtainedAt): InventoryItem
    {
        return $this->items->grant($userId, $itemKey, $lootRollId, $obtainedAt);
    }

    /**
     * Achète un exemplaire d'`$item` (#229) — un {@see grant()} sans tirage, `lootRollId` à
     * `null`, voir le docblock d'{@see InventoryItem} pour ce que `null` veut dire.
     *
     * **Refuse un `EQUIPMENT` déjà possédé, sans méthode dédiée sur le repository.** `grant()`
     * verrouille déjà, lit déjà la possession, écrit déjà — une méthode `purchase()` sur
     * {@see InventoryItemRepository} dupliquerait ces trois gestes pour une seule différence :
     * refuser plutôt que fusionner. Cette classe appelle donc `grant()` tel quel, sous le même
     * verrou, et lit la quantité qui en ressort : `1` est un objet neuf, tout le reste ne peut
     * venir que d'une ligne déjà là *avant* cet appel — un tirage passé, ou un achat
     * précédent. La transaction ouverte par {@see PurchaseItemHandler} annule cette écriture
     * avec le reste si c'est le cas ; le ledger de pièces n'a pas encore été touché à ce
     * stade, voir son docblock pour l'ordre des deux verrous.
     *
     * **Un coffre échappe à ce refus (#230).** {@see ItemAlreadyOwned}
     * se justifie par un unique emplacement qui n'accueillerait rien de plus — voir son
     * docblock — un raisonnement qui ne tient pas pour un coffre, qui ne s'équipe jamais et
     * s'empile au contraire pour de bon : chaque achat est une future ouverture de plus. La
     * boutique étant le seul donneur (#230), c'est même la seule façon d'en posséder plus
     * d'un. `$item->kind` tranche donc ici, jamais un drapeau supplémentaire au catalogue.
     *
     * @throws ItemAlreadyOwned `$item` est un `EQUIPMENT` déjà possédé avant cet achat
     */
    public function purchase(Uuid $userId, Item $item, DateTimeImmutable $obtainedAt): InventoryItem
    {
        $line = $this->items->grant($userId, $item->key, null, $obtainedAt);

        if (ItemKind::Equipment === $item->kind && $line->quantity() > 1) {
            throw new ItemAlreadyOwned($item->key);
        }

        return $line;
    }
}
