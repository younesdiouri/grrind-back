<?php

declare(strict_types=1);

namespace App\Rewards\Infrastructure\Doctrine;

use App\Rewards\Domain\EquipmentSlot;
use App\Rewards\Domain\Exception\ItemNotOwned;
use App\Rewards\Domain\InventoryItem;
use App\Rewards\Domain\Item;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * L'inventaire d'un joueur : possession, port et dépossession, chacun sous verrou (#29).
 *
 * ## Le verrou : un compte, comme pour les pièces
 *
 * Même geste et mêmes raisons qu'{@see CoinTransactionRepository} — voir son docblock pour
 * pourquoi `pg_advisory_xact_lock` plutôt que le composant `Lock` de Symfony ou un verrou de
 * ligne : il n'y a rien à verrouiller *avant* qu'une première ligne existe pour un objet
 * donné, exactement le problème que {@see grant()} rencontre en premier. La clé salée par
 * `':inventory'` — et non `hashtext(user_id)` nu comme pour les pièces — évite de faire
 * attendre un crédit de pièces derrière un équipement et réciproquement : les deux ledgers
 * n'ont aucune raison de se sérialiser l'un derrière l'autre.
 *
 * ## `equip()` et `unequip()` portent toute la règle, pas seulement l'écriture
 *
 * Même choix que {@see CoinTransactionRepository::record()} : la vérification de possession
 * vit dans le repository, sous le même verrou et la même transaction que l'écriture, parce
 * qu'une vérification faite avant le verrou pourrait être périmée au moment d'écrire.
 *
 * ## `equip()` échange, il ne refuse jamais un emplacement occupé
 *
 * `PUT /api/inventory/equipment/{slot}` (#30) dit « fais que cet emplacement contienne ceci » —
 * la sémantique d'un `PUT` est de remplacer, pas de constater un conflit. Un joueur qui
 * possède deux paires de bottes et en porte déjà une n'a pas à faire un `DELETE` puis un
 * `PUT` : deux requêtes, une fenêtre où il ne porte rien, et un état intermédiaire à rattraper
 * si la seconde échoue. `equip()` sort donc l'occupant de l'emplacement avant d'y poser le
 * nouvel objet, dans la même transaction, sous le même verrou. **Ce n'est pas une perte** :
 * l'occupant retourne dans le sac ({@see InventoryItem::unequip()}), il ne quitte
 * l'inventaire à aucun moment — voir {@see equip()} pour pourquoi deux `flush()` distincts.
 *
 * @extends ServiceEntityRepository<InventoryItem>
 */
class InventoryItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InventoryItem::class);
    }

    /**
     * Crédite un exemplaire de `$itemKey`, sous verrou pour éviter que deux tirages
     * concurrents du même objet — deux appareils qui synchronisent en même temps — ne créent
     * chacun leur propre ligne au lieu d'incrémenter la même.
     */
    public function grant(Uuid $userId, string $itemKey, Uuid $lootRollId, DateTimeImmutable $obtainedAt): InventoryItem
    {
        return $this->getEntityManager()->wrapInTransaction(function () use ($userId, $itemKey, $lootRollId, $obtainedAt): InventoryItem {
            $this->lock($userId);

            $owned = $this->ofPlayerAndItem($userId, $itemKey);

            if (null !== $owned) {
                $owned->grantOneMore();
                $this->getEntityManager()->flush();

                return $owned;
            }

            $item = InventoryItem::firstGrant($userId, $itemKey, $lootRollId, $obtainedAt);
            $this->getEntityManager()->persist($item);
            $this->getEntityManager()->flush();

            return $item;
        });
    }

    /**
     * Porte `$item` dans `$slot`, sous verrou. `$slot` est déjà vérifié compatible avec
     * `$item` par {@see \App\Rewards\Application\EquipItemHandler} avant l'appel — une
     * vérification pure, qui n'a besoin d'aucun verrou puisqu'elle ne regarde que le
     * catalogue, jamais l'inventaire.
     *
     * **Échange plutôt que refus** — voir le docblock de la classe pour pourquoi. Si un
     * *autre* objet occupe déjà `$slot`, il en est retiré (il redevient un objet du sac,
     * toujours possédé) avant que `$item` y entre. Le retrait se flush séparément, **avant**
     * la pose du nouvel objet : l'index unique partiel `(user_id, slot) WHERE slot IS NOT
     * NULL` n'est pas différable, Postgres le vérifie à la fin de chaque instruction — flush
     * les deux mutations ensemble risquerait que l'`UPDATE` qui pose le nouvel objet
     * s'exécute avant celui qui libère l'ancien, et percute la contrainte pour un état qui
     * n'aurait pourtant duré qu'un instant.
     *
     * `$occupant === $owned` (le même objet déjà dans ce même emplacement) est un
     * ré-équipement idempotent : rien à échanger, `equipInto()` repose la même valeur.
     *
     * @throws ItemNotOwned aucune ligne pour `$item`, ou une quantité nulle
     */
    public function equip(Uuid $userId, Item $item, EquipmentSlot $slot): InventoryItem
    {
        return $this->getEntityManager()->wrapInTransaction(function () use ($userId, $item, $slot): InventoryItem {
            $this->lock($userId);

            $owned = $this->ofPlayerAndItem($userId, $item->key);

            if (null === $owned || $owned->quantity() < 1) {
                throw new ItemNotOwned($item->key);
            }

            $occupant = $this->equippedIn($userId, $slot);

            if (null !== $occupant && $occupant->itemKey() !== $item->key) {
                $occupant->unequip();
                // Flush isolé : voir le docblock de la méthode pour pourquoi cet ordre n'est
                // pas négociable face à un index unique non différable.
                $this->getEntityManager()->flush();
            }

            $owned->equipInto($slot);
            $this->getEntityManager()->flush();

            return $owned;
        });
    }

    /**
     * Vide `$slot`, sous verrou. Idempotent : déséquiper un emplacement déjà vide ne
     * produit aucune erreur, même geste qu'un `DELETE` sur une ressource déjà absente — il
     * n'y a rien d'incorrect à demander de retirer ce qui ne porte déjà personne.
     */
    public function unequip(Uuid $userId, EquipmentSlot $slot): void
    {
        $this->getEntityManager()->wrapInTransaction(function () use ($userId, $slot): void {
            $this->lock($userId);

            $occupant = $this->equippedIn($userId, $slot);

            if (null === $occupant) {
                return;
            }

            $occupant->unequip();
            $this->getEntityManager()->flush();
        });
    }

    /** `findOneBy()` plutôt qu'une requête à la main : une ligne se cherche déjà par ces deux colonnes seules, voir l'unicité de la classe. */
    public function ofPlayerAndItem(Uuid $userId, string $itemKey): ?InventoryItem
    {
        return $this->findOneBy(['userId' => $userId, 'itemKey' => $itemKey]);
    }

    public function equippedIn(Uuid $userId, EquipmentSlot $slot): ?InventoryItem
    {
        return $this->findOneBy(['userId' => $userId, 'slot' => $slot]);
    }

    /**
     * Tout ce qu'un joueur porte, toutes les entrées avec un emplacement non nul — la seule
     * lecture dont {@see \App\Rewards\Application\ItemModifiers} a besoin : une requête plutôt
     * qu'une itération sur chaque cas d'{@see EquipmentSlot}. `findBy()` ne sait pas exprimer
     * `IS NOT NULL`, d'où le passage par le QueryBuilder ici, contrairement aux deux méthodes
     * ci-dessus.
     *
     * @return list<InventoryItem>
     */
    public function equippedByPlayer(Uuid $userId): array
    {
        /** @var list<InventoryItem> $items */
        $items = $this->createQueryBuilder('i')
            ->andWhere('i.userId = :userId')
            ->andWhere('i.slot IS NOT NULL')
            ->setParameter('userId', $userId, UuidType::NAME)
            ->getQuery()
            ->getResult();

        return $items;
    }

    /**
     * Voir le docblock de la classe pour pourquoi cette clé est salée différemment de celle
     * d'{@see CoinTransactionRepository}.
     */
    private function lock(Uuid $userId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'SELECT pg_advisory_xact_lock(hashtext(:key))',
            ['key' => $userId->toRfc4122().':inventory'],
        );
    }
}
