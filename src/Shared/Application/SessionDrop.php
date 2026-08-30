<?php

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Ce qu'un workout crédité a fait tomber, tel que `Training` le reçoit de `Rewards` pour
 * compléter le `RewardSummary` (#226) — le pendant de {@see SessionReward} pour le loot,
 * jamais fusionné avec lui : voir le docblock de {@see SessionDrops} pour pourquoi deux
 * matières différentes gardent deux contrats.
 *
 * **`coinsBefore`/`coinsAfter` sont lus sur le solde réel du joueur, jamais reconstitués à
 * partir de `coinsGained`.** Même règle et même raison que le palier de niveau sur
 * `SessionReward` : sur un import de dix workouts, la bourse s'anime dix fois d'affilée, et
 * un client qui recevrait `after = before + gained` pourrait diverger du vrai solde au
 * premier écart — un rejeu, une écriture concurrente — sans que personne ne le remarque.
 *
 * **Vide n'est pas absent.** {@see none()} produit la forme qu'une séance non créditée ou
 * sans table éligible doit quand même porter : aucun objet, aucun gain, mais un solde réel
 * et identique avant et après — jamais deux zéros qui mentiraient sur le montant que le
 * joueur possède déjà.
 */
final readonly class SessionDrop
{
    /**
     * @param list<DroppedItem> $items vide le plus souvent : voir le docblock de `LootRoller`
     */
    public function __construct(
        public array $items,
        public int $coinsGained,
        public int $coinsBefore,
        public int $coinsAfter,
    ) {
    }

    /** Rien n'est tombé — voir le docblock de la classe pour pourquoi `$balance` voyage quand même. */
    public static function none(int $balance): self
    {
        return new self([], 0, $balance, $balance);
    }
}
