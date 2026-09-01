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
use App\Shared\Application\DroppedItem;
use App\Shared\Application\DroppedItemModifier;
use App\Shared\Application\ModifierResolver;
use App\Shared\Application\SessionDrop;
use App\Shared\Application\SessionDrops;
use App\Shared\Application\SessionReward;
use App\Shared\Domain\Event\WorkoutImported;
use LogicException;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * L'implémentation du port {@see SessionDrops} : c'est par cette classe, et uniquement
 * par elle, que `Training` fait tomber du loot sur une séance.
 *
 * ## Le pipeline, dans l'ordre
 *
 * 1. **le verdict de crédit** — `$reward->reason` — voir le docblock du port pour pourquoi
 *    la question ne se repose pas ici : une valeur non nulle (la marche, #167) arrête tout
 *    avant le premier tirage, et {@see SessionDrop::none()} rend la forme vide sur le
 *    solde réel ;
 * 2. `random_bytes(32)` tire la graine **de ce workout**, jamais partagée avec un autre du
 *    même lot — même exigence et même piège que documentés sur {@see \App\Combat\Domain\Battle} au #209 ;
 * 3. {@see LootRoller::rollForWorkout()} choisit une table, pur, sur le `Randomizer`
 *    grainé — `null` sans table éligible, voir son docblock ;
 * 4. la ligne d'audit {@see LootRoll} s'écrit et se flush **avant** l'inventaire et les
 *    pièces, même ordre que `Training` écrit le workout avant de le créditer : les deux
 *    écritures suivantes référencent son identifiant, une écriture qui pointerait une ligne
 *    encore absente serait une piste qui ne mène nulle part ;
 * 5. {@see Inventory::grant()} crédite chaque objet tiré, {@see CoinLedger::credit()}
 *    crédite les pièces — sauf si la bande a tiré zéro, voir plus bas.
 *
 * **Le niveau qui ouvre les tables est celui d'après ce crédit — `$reward->level`, jamais
 * une seconde lecture du snapshot.** La séquence de `ARCHITECTURE.md` place le loot après
 * l'XP et les titres : si cette séance vient de faire franchir un niveau, la table qu'elle
 * ouvre doit déjà en tenir compte, exactement comme {@see \App\Progression\Application\GrantXpHandler}
 * évalue les titres après avoir écrit le ledger plutôt qu'avant.
 *
 * **`LOOT_LUCK` se résout à la date du sport**, comme tout le reste de la transaction de
 * complétion (#190) : un objet équipé aujourd'hui ne doit pas bonifier un workout d'il y a
 * trois semaines qu'un import vient de remonter.
 *
 * **Aucune écriture de pièces si la bande a tiré zéro.** {@see CoinLedger::credit()} exige
 * un montant strictement positif ; une bande à zéro reste possible en théorie
 * (`minimum: 0` dans `loot.yaml`) même si aucune table livrée ne le fait aujourd'hui, et ce
 * n'est pas une raison d'écrire une ligne qui ne changerait rien au solde.
 */
final readonly class WorkoutSessionDrops implements SessionDrops
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

    public function rollFor(WorkoutImported $workout, SessionReward $reward): SessionDrop
    {
        // Une discipline qui ne crédite pas d'XP par conception (#167) n'ouvre aucun
        // tirage : voir le docblock du port pour pourquoi cette classe se contente de lire
        // ce que `LedgerSessionRewards` a déjà décidé.
        if (null !== $reward->reason) {
            return SessionDrop::none($this->coins->balanceOf($workout->userId));
        }

        // Exactement 32 octets, jamais un hash d'une chaîne — même piège que documenté sur
        // `Battle` au #209 — et une graine par workout, jamais par synchronisation : deux
        // séances du même lot ne doivent rien partager de leur hasard.
        $seed = random_bytes(32);
        $randomizer = new Randomizer(new Xoshiro256StarStar($seed));

        $outcome = $this->roller->rollForWorkout(
            $this->tables,
            $workout->discipline,
            intdiv($workout->durationSeconds, 60),
            $reward->level,
            $this->modifiers->of($workout->userId, $workout->occurredAt()),
            $randomizer,
        );

        if (null === $outcome) {
            return SessionDrop::none($this->coins->balanceOf($workout->userId));
        }

        $roll = LootRoll::record(
            $workout->userId,
            LootRollOrigin::Workout,
            $workout->workoutId,
            $seed,
            $outcome,
            $workout->occurredAt(),
        );
        $this->rolls->add($roll);
        $this->rolls->commit();

        $items = [];

        foreach ($outcome->items as $itemKey) {
            $this->inventory->grant($workout->userId, $itemKey, $roll->id(), $workout->occurredAt());
            $items[] = $this->describe($itemKey);
        }

        $before = $this->coins->balanceOf($workout->userId);

        if ($outcome->coins > 0) {
            $this->coins->credit($workout->userId, CoinReason::WorkoutDrop, $roll->id(), $outcome->coins, $workout->occurredAt());
        }

        $after = $this->coins->balanceOf($workout->userId);

        return new SessionDrop($items, $outcome->coins, $before, $after);
    }

    /**
     * Un objet du catalogue, tel que le client le reçoit — voir le docblock de
     * {@see DroppedItem} pour pourquoi `Shared` ne porte que des chaînes.
     *
     * **Exige un emplacement non nul.** Un coffre ne tombe jamais d'un tirage de séance —
     * voir « Personne ne donne de coffre en dehors de la boutique » au #230 — donc `$item`
     * ici est toujours un `EQUIPMENT` : un `slot` nul serait un bug de configuration, pas un
     * cas à absorber en silence.
     */
    private function describe(string $itemKey): DroppedItem
    {
        $item = $this->catalog->find($itemKey)
            ?? throw new LogicException(\sprintf('"%s" est tombé d\'un tirage mais n\'existe plus dans le catalogue.', $itemKey));

        $slot = $item->slot
            ?? throw new LogicException(\sprintf('"%s" est tombé d\'un tirage de séance sans emplacement : un coffre ne devrait jamais y figurer, voir le docblock de LootTables.', $itemKey));

        return new DroppedItem(
            $item->key,
            $item->kind->value,
            $this->translator->nameOf($item->key),
            $item->rarity->value,
            $slot->value,
            array_map(
                static fn (ItemModifier $modifier): DroppedItemModifier => new DroppedItemModifier(
                    $modifier->type->value,
                    $modifier->value,
                    $modifier->discipline?->value,
                ),
                $item->modifiers,
            ),
            $item->priceCoins,
            $this->translator->imageUrlOf($item->key),
        );
    }
}
