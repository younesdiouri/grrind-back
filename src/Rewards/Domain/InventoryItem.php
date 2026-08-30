<?php

declare(strict_types=1);

namespace App\Rewards\Domain;

use App\Rewards\Infrastructure\Doctrine\InventoryItemRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Ce qu'un joueur possède d'un objet du catalogue — **une ligne par (joueur, objet)**, pas
 * une ligne par exemplaire tiré. C'est le ticket #29 qui pose cette table ; `ItemCatalog`
 * (#27) ne dit que ce qu'un objet *est*, jamais qui le possède.
 *
 * ## Une seule table plutôt qu'un inventaire et un équipement séparés
 *
 * `$slot` porte l'emplacement où l'objet est **porté**, ou `null` s'il dort dans le sac. Une
 * table d'équipement à part aurait exigé une jointure pour une contrainte que l'unicité
 * partielle `uniq_rewards_inventory_item_equipped_slot` — `(user_id, slot) WHERE slot IS NOT
 * NULL` — exprime déjà en base : au plus un objet par emplacement, tenu par PostgreSQL, pas
 * par un `if` dans une transaction. Même geste que {@see \App\Community\Domain\Risala} pour
 * `uniq_community_risala_open_turn`.
 *
 * ## `$quantity` ne redescend jamais dans ce ticket
 *
 * « Aucune vente, aucun rebut, aucun objet consommable » (#29) : la seule mutation de
 * `$quantity` est {@see grantOneMore()}, strictement additive. Le jour où une dépense existera
 * (boutique, Lot 6b), elle touchera une classe qui l'assume plutôt que de réutiliser
 * silencieusement celle-ci pour un geste qu'elle ne portait pas.
 *
 * ## `$lootRollId` est la provenance, et rien d'autre ne la porte
 *
 * {@see LootRoll} audite déjà le tirage en entier — origine, cause, graine, poids. Une
 * seconde façon de raconter « d'où vient cet objet » sur cette ligne serait une source de
 * vérité de plus à garder synchrone avec la première. `$lootRollId` pointe donc vers la ligne
 * d'audit plutôt que de recopier `origin`/`causeId` : consulter l'historique complet d'un
 * exemplaire, c'est relire ce tirage-là.
 *
 * **`$lootRollId` et `$obtainedAt` figent la *première* acquisition, jamais la dernière.**
 * Une ligne peut recevoir plusieurs tirages du même objet au fil du temps — {@see
 * grantOneMore()} augmente `$quantity` sans y toucher. « Date d'obtention », pour un joueur,
 * veut dire la première fois qu'il a eu l'objet : un objet gagné il y a trois mois qui
 * retombe aujourd'hui ne date pas d'aujourd'hui, et écraser la provenance à chaque doublon
 * ferait perdre cette réponse sans qu'aucune autre ligne ne la garde. Ce n'est pas une perte
 * d'information pour autant : l'historique complet de chaque tirage individuel, y compris
 * ceux qui n'ont fait qu'incrémenter cette ligne, reste entier dans sa propre `LootRoll`.
 *
 * ## Pas de clé étrangère
 *
 * `$userId` est un UUID nu, même choix et mêmes raisons que {@see CoinTransaction} et
 * {@see LootRoll} : `Rewards` ne connaît pas `Identity`. `$lootRollId` référence une entité du
 * **même** module — rien n'empêcherait une vraie clé étrangère ici — mais reste un UUID nu par
 * cohérence avec le reste de la classe : aucune requête de ce module ne charge jamais
 * `InventoryItem` et `LootRoll` ensemble par une relation Doctrine, donc rien n'achèterait la
 * jointure.
 *
 * ## Mutable, contrairement à ses voisins `CoinTransaction` et `LootRoll`
 *
 * Ce n'est pas un ledger : un solde d'objets ou un emplacement porté sont un état, pas une
 * suite d'écritures. `grantOneMore()`, `equipInto()` et `unequip()` mutent la ligne en place,
 * et c'est le geste correct ici — l'invariant append-only du projet protège des **valeurs de
 * jeu créditées**, pas la position d'un objet dans un sac.
 */
#[ORM\Entity(repositoryClass: InventoryItemRepository::class)]
#[ORM\Table(name: 'rewards_inventory_item')]
// Une ligne par joueur et par objet : c'est la définition même de cette table, voir le
// docblock de la classe. Sert aussi de point d'entrée pour retrouver un objet précis.
#[ORM\UniqueConstraint(name: 'uniq_rewards_inventory_item_player_item', columns: ['user_id', 'item_key'])]
// Au plus un objet par emplacement, et c'est la base qui le tient — voir le docblock de la
// classe. Partiel : un objet qui dort dans le sac (`slot IS NULL`) ne dispute aucun
// emplacement, `NULL` ne participant de toute façon jamais à une contrainte d'unicité
// classique en SQL — le `WHERE` le dit explicitement plutôt que de compter sur ce
// comportement implicite.
#[ORM\UniqueConstraint(name: 'uniq_rewards_inventory_item_equipped_slot', columns: ['user_id', 'slot'], options: ['where' => '(slot IS NOT NULL)'])]
class InventoryItem
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $userId;

    /** La clé d'{@see Item} dans {@see ItemCatalog} — pas de clé étrangère, le catalogue n'est pas une table. */
    #[ORM\Column(length: 64)]
    private string $itemKey;

    #[ORM\Column]
    private int $quantity;

    /** `null` = dans le sac. Voir le docblock de la classe pour l'unicité qui le garde. */
    #[ORM\Column(length: 16, nullable: true, enumType: EquipmentSlot::class)]
    private ?EquipmentSlot $slot = null;

    /** Le tirage qui a produit le tout premier exemplaire — voir le docblock de la classe. */
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $lootRollId;

    /** La date de la première acquisition, jamais celle de l'écriture — même geste que partout ailleurs. */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $obtainedAt;

    private function __construct(Uuid $userId, string $itemKey, int $quantity, Uuid $lootRollId, DateTimeImmutable $obtainedAt)
    {
        $this->id = Uuid::v7();
        $this->userId = $userId;
        $this->itemKey = $itemKey;
        $this->quantity = $quantity;
        $this->lootRollId = $lootRollId;
        $this->obtainedAt = $obtainedAt;
    }

    /** Le premier exemplaire d'un objet pour ce joueur — voir {@see grantOneMore()} pour les suivants. */
    public static function firstGrant(Uuid $userId, string $itemKey, Uuid $lootRollId, DateTimeImmutable $obtainedAt): self
    {
        return new self($userId, $itemKey, 1, $lootRollId, $obtainedAt);
    }

    /**
     * Un tirage supplémentaire du même objet, pour ce même joueur. Toujours `+1` : un tirage
     * ne rend jamais plus d'un exemplaire du même objet à la fois, voir le docblock de
     * {@see LootRollOutcome}.
     *
     * Ne touche ni `$lootRollId` ni `$obtainedAt` — voir le docblock de la classe : la
     * provenance reste celle de la première acquisition, jamais celle du doublon qui vient
     * d'arriver.
     */
    public function grantOneMore(): void
    {
        ++$this->quantity;
    }

    /**
     * Porte cet objet dans `$slot`. N'a aucune opinion sur la compatibilité de l'emplacement
     * ni sur ce qui s'y trouvait déjà — ces règles sont de {@see \App\Rewards\Application\EquipItemHandler}
     * et de {@see InventoryItemRepository::equip()}, qui
     * connaissent le catalogue et le reste de l'inventaire ; cette méthode ne fait que poser
     * le fait une fois la décision prise.
     */
    public function equipInto(EquipmentSlot $slot): void
    {
        $this->slot = $slot;
    }

    /** Renvoie l'objet dans le sac. Idempotent : déséquiper un objet déjà dans le sac ne change rien. */
    public function unequip(): void
    {
        $this->slot = null;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function userId(): Uuid
    {
        return $this->userId;
    }

    public function itemKey(): string
    {
        return $this->itemKey;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function slot(): ?EquipmentSlot
    {
        return $this->slot;
    }

    public function lootRollId(): Uuid
    {
        return $this->lootRollId;
    }

    public function obtainedAt(): DateTimeImmutable
    {
        return $this->obtainedAt;
    }
}
