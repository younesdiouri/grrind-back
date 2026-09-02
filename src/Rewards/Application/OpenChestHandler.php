<?php

declare(strict_types=1);

namespace App\Rewards\Application;

use App\Rewards\Domain\CoinReason;
use App\Rewards\Domain\Exception\ItemNotAChest;
use App\Rewards\Domain\Exception\ItemNotOwned;
use App\Rewards\Domain\ItemCatalog;
use App\Rewards\Domain\ItemKind;
use App\Rewards\Domain\LootRoll;
use App\Rewards\Domain\LootRoller;
use App\Rewards\Domain\LootRollOrigin;
use App\Rewards\Domain\LootTables;
use App\Rewards\Infrastructure\Doctrine\InventoryItemRepository;
use App\Rewards\Infrastructure\Doctrine\LootRollRepository;
use App\Shared\Application\ModifierResolver;
use LogicException;
use Psr\Clock\ClockInterface;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Ouvrir un coffre, de bout en bout (#230) : consommer un exemplaire, tirer, écrire
 * l'audit, créditer objets et pièces — une transaction, exactement le pipeline de
 * {@see \App\Rewards\Infrastructure\Drop\WorkoutSessionDrops} et
 * {@see \App\Rewards\Infrastructure\Drop\AdversaryBattleDrops}, avec un troisième déclencheur
 * plutôt qu'un second moteur : {@see LootRoller::rollForChest()} est le jumeau exact de
 * `rollForAdversary()`.
 *
 * ## L'ordre des vérifications, et pourquoi
 *
 * Même structure qu'{@see PurchaseItemHandler} et {@see EquipItemHandler} : les lectures
 * pures d'abord, ce qui dépend d'un état mutable sous verrou ensuite.
 *
 * 1. `$command->chestKey` se résout contre {@see ItemCatalog} — une clé inconnue emprunte
 *    {@see ItemNotOwned} plutôt qu'une exception de plus, même raisonnement que sur
 *    {@see EquipItemHandler} : un objet inconnu ne peut par construction être possédé par
 *    personne ;
 * 2. `$item->kind` doit être {@see ItemKind::Chest} — sinon {@see ItemNotAChest}, une
 *    lecture pure du catalogue qui n'a besoin d'aucune transaction ;
 * 3. la possession, seule vérification qui dépend d'un état mutable, se fait **sous
 *    verrou** dans {@see InventoryItemRepository::consumeOne()}.
 *
 * ## Une seule transaction, verrous dans l'ordre du chemin de drop
 *
 * **L'inventaire se verrouille avant les pièces, jamais l'inverse** — même geste et même
 * raison qu'{@see PurchaseItemHandler} : {@see InventoryItemRepository::transactional()}
 * ouvre la transaction unique, `consumeOne()` prend le verrou d'inventaire en premier, et
 * {@see CoinLedger::credit()} ne traverse le verrou des pièces qu'ensuite, si la bande de
 * la table a tiré plus de zéro.
 *
 * `causeId` de la ligne {@see LootRoll} est l'identifiant de la ligne d'inventaire du coffre
 * consommé : l'exemplaire ouvert n'a pas d'identité propre — voir le docblock d'{@see
 * \App\Rewards\Domain\InventoryItem} — donc rien d'autre à référencer, même geste que
 * `sourceId` de la ligne `PURCHASE` pointant la ligne achetée.
 */
final readonly class OpenChestHandler
{
    public function __construct(
        private ItemCatalog $catalog,
        private InventoryItemRepository $inventoryRepository,
        private Inventory $inventory,
        private LootRoller $roller,
        private LootTables $tables,
        private ModifierResolver $modifiers,
        private LootRollRepository $rolls,
        private CoinLedger $coins,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ItemNotOwned  `$command->chestKey` est inconnue du catalogue, ou non possédée (quantité nulle comprise)
     * @throws ItemNotAChest `$command->chestKey` désigne un objet du catalogue qui n'est pas un coffre
     */
    public function __invoke(OpenChest $command): ChestOpenReceipt
    {
        $item = $this->catalog->find($command->chestKey) ?? throw new ItemNotOwned($command->chestKey);

        if (ItemKind::Chest !== $item->kind) {
            throw new ItemNotAChest($item->key);
        }

        // Une ouverture a lieu à l'instant de la requête, comme un achat ou un combat — pas
        // d'antériorité à arbitrer, contrairement à un workout. Un seul appel, réutilisé
        // pour la consommation, le tirage et les deux écritures ci-dessous.
        $now = $this->clock->now();

        return $this->inventoryRepository->transactional(function () use ($command, $item, $now): ChestOpenReceipt {
            $line = $this->inventoryRepository->consumeOne($command->userId, $item->key);

            // Exactement 32 octets, jamais un hash d'une chaîne — même piège que documenté
            // sur `Battle` au #209 — et une graine indépendante de tout autre tirage : une
            // ouverture est son propre événement de jeu, audité pour lui-même.
            $seed = random_bytes(32);
            $randomizer = new Randomizer(new Xoshiro256StarStar($seed));

            $outcome = $this->roller->rollForChest(
                $this->tables,
                $item->key,
                $this->modifiers->of($command->userId, $now),
                $randomizer,
            );

            // `RewardsCoverageTest` garantit que tout coffre livré a sa table — voir le
            // docblock d'`ItemCatalog` — donc un `null` ici n'est jamais un cas de jeu
            // légitime, contrairement à `rollForAdversary()` sur un ennemi neuf sans table
            // encore écrite : c'est une configuration incohérente que ce ticket refuse
            // d'absorber en silence.
            if (null === $outcome) {
                throw new LogicException(\sprintf('"%s" est un coffre du catalogue sans table de tirage dans le snapshot publié.', $item->key));
            }

            $roll = LootRoll::record(
                $command->userId,
                LootRollOrigin::Chest,
                $line->id(),
                $seed,
                $outcome,
                $now,
            );
            $this->rolls->add($roll);
            $this->rolls->commit();

            $items = [];

            foreach ($outcome->items as $itemKey) {
                $this->inventory->grant($command->userId, $itemKey, $roll->id(), $now);
                $items[] = $this->catalog->find($itemKey)
                    ?? throw new LogicException(\sprintf('"%s" est tombé d\'un tirage de coffre mais n\'existe plus dans le catalogue.', $itemKey));
            }

            $coinsBefore = $this->coins->balanceOf($command->userId);

            if ($outcome->coins > 0) {
                $this->coins->credit($command->userId, CoinReason::Chest, $roll->id(), $outcome->coins, $now);
            }

            $coinsAfter = $this->coins->balanceOf($command->userId);

            return new ChestOpenReceipt($items, $outcome->coins, $coinsBefore, $coinsAfter);
        });
    }
}
