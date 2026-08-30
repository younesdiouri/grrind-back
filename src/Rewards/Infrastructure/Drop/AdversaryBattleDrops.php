<?php

declare(strict_types=1);

namespace App\Rewards\Infrastructure\Drop;

use App\Rewards\Application\CoinLedger;
use App\Rewards\Application\Inventory;
use App\Rewards\Domain\CoinReason;
use App\Rewards\Domain\ItemCatalog;
use App\Rewards\Domain\ItemModifier;
use App\Rewards\Domain\LootRoll;
use App\Rewards\Domain\LootRoller;
use App\Rewards\Domain\LootRollOrigin;
use App\Rewards\Domain\LootTables;
use App\Rewards\Infrastructure\Doctrine\LootRollRepository;
use App\Rewards\Infrastructure\Translation\ItemTranslator;
use App\Shared\Application\BattleDrop;
use App\Shared\Application\BattleDrops;
use App\Shared\Application\DroppedItem;
use App\Shared\Application\DroppedItemModifier;
use App\Shared\Application\ModifierResolver;
use DateTimeImmutable;
use LogicException;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use Symfony\Component\Uid\Uuid;

/**
 * L'implémentation du port {@see BattleDrops} : c'est par cette classe, et uniquement par
 * elle, que `Combat` fait tomber du loot sur une victoire — le jumeau exact de
 * {@see WorkoutSessionDrops} pour l'adversaire plutôt que la séance.
 *
 * ## Le pipeline, dans l'ordre
 *
 * 1. **le verdict** — `$victory` — voir le docblock du port pour pourquoi la question ne se
 *    repose pas ici : une défaite, ou une victoire par `max_turns` sans KO, arrête tout
 *    avant le premier tirage, et {@see BattleDrop::none()} rend la forme vide sur le solde
 *    réel ;
 * 2. `random_bytes(32)` tire la graine **de ce combat**, jamais partagée avec la graine qui
 *    a joué le combat lui-même — un tirage de loot est un événement de jeu distinct, audité
 *    pour lui-même, même règle que sur un drop de séance ;
 * 3. {@see LootRoller::rollForAdversary()} choisit la table de `$enemyKey`, pur, sur le
 *    `Randomizer` grainé — `null` sans table dédiée pour cet adversaire, voir son docblock ;
 * 4. la ligne d'audit {@see LootRoll} s'écrit et se flush **avant** l'inventaire et les
 *    pièces, même ordre que `WorkoutSessionDrops` : les deux écritures suivantes référencent
 *    son identifiant, une écriture qui pointerait une ligne encore absente serait une piste
 *    qui ne mène nulle part ;
 * 5. {@see Inventory::grant()} crédite chaque objet tiré, {@see CoinLedger::credit()}
 *    crédite les pièces — sauf si la bande a tiré zéro, même exception que sur un drop de
 *    séance.
 *
 * **`LOOT_LUCK` se résout à l'instant du combat**, comme le reste de ce qui dérive un
 * combattant (#224) : un objet équipé au moment du combat, jamais un instant antérieur —
 * un combat n'a aucune antériorité au serveur, contrairement à un workout.
 *
 * **La portée par discipline de `LOOT_LUCK` ne s'applique pas ici, et ce n'est pas une
 * asymétrie à corriger.** Un combat n'a lieu « dans » aucune discipline — voir le docblock
 * de {@see LootRoller} pour « La portée par discipline compte ici », qui explique pourquoi
 * `rollForAdversary()` filtre différemment de `rollForWorkout()`. Cette classe se contente
 * de passer les modificateurs déjà résolus ; le tri lui-même appartient à `LootRoller`, pas
 * à elle.
 *
 * **Aucune écriture de pièces si la bande a tiré zéro.** Même exception que sur
 * {@see WorkoutSessionDrops}, pour la même raison : {@see CoinLedger::credit()} exige un
 * montant strictement positif.
 */
final readonly class AdversaryBattleDrops implements BattleDrops
{
    public function __construct(
        private LootRoller $roller,
        private LootTables $tables,
        private ModifierResolver $modifiers,
        private LootRollRepository $rolls,
        private Inventory $inventory,
        private CoinLedger $coins,
        private ItemCatalog $catalog,
        private ItemTranslator $translator,
    ) {
    }

    public function rollFor(Uuid $playerId, string $enemyKey, bool $victory, Uuid $battleId, DateTimeImmutable $foughtAt): BattleDrop
    {
        // Défaite, ou victoire tranchée par `max_turns` sans KO : aucun tirage — voir le
        // docblock du port pour pourquoi cette classe se contente de lire le verdict que
        // `FightBattleHandler` lui a déjà rendu.
        if (!$victory) {
            return BattleDrop::none($this->coins->balanceOf($playerId));
        }

        // Exactement 32 octets, jamais un hash d'une chaîne — même piège que documenté sur
        // `Battle` au #209 — et une graine indépendante de celle qui a joué le combat : le
        // tirage de loot est un second événement de jeu, audité pour lui-même.
        $seed = random_bytes(32);
        $randomizer = new Randomizer(new Xoshiro256StarStar($seed));

        $outcome = $this->roller->rollForAdversary(
            $this->tables,
            $enemyKey,
            $this->modifiers->of($playerId, $foughtAt),
            $randomizer,
        );

        if (null === $outcome) {
            return BattleDrop::none($this->coins->balanceOf($playerId));
        }

        $roll = LootRoll::record(
            $playerId,
            LootRollOrigin::Battle,
            $battleId,
            $seed,
            $outcome,
            $foughtAt,
        );
        $this->rolls->add($roll);
        $this->rolls->commit();

        $items = [];

        foreach ($outcome->items as $itemKey) {
            $this->inventory->grant($playerId, $itemKey, $roll->id(), $foughtAt);
            $items[] = $this->describe($itemKey);
        }

        $before = $this->coins->balanceOf($playerId);

        if ($outcome->coins > 0) {
            $this->coins->credit($playerId, CoinReason::BattleDrop, $roll->id(), $outcome->coins, $foughtAt);
        }

        $after = $this->coins->balanceOf($playerId);

        return new BattleDrop($items, $outcome->coins, $before, $after);
    }

    /**
     * Un objet du catalogue, tel que le client le reçoit — voir le docblock de
     * {@see DroppedItem} pour pourquoi `Shared` ne porte que des chaînes. Même geste que
     * {@see WorkoutSessionDrops::describe()}.
     */
    private function describe(string $itemKey): DroppedItem
    {
        $item = $this->catalog->find($itemKey)
            ?? throw new LogicException(\sprintf('"%s" est tombé d\'un tirage mais n\'existe plus dans le catalogue.', $itemKey));

        return new DroppedItem(
            $item->key,
            $this->translator->nameOf($item->key),
            $item->rarity->value,
            $item->slot->value,
            array_map(
                static fn (ItemModifier $modifier): DroppedItemModifier => new DroppedItemModifier(
                    $modifier->type->value,
                    $modifier->value,
                    $modifier->discipline?->value,
                ),
                $item->modifiers,
            ),
            $item->priceCoins,
        );
    }
}
