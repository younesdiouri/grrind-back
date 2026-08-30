<?php

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Ce qu'un combat gagné a fait tomber, tel que `Combat` le reçoit de `Rewards` pour
 * compléter la réponse de `POST /api/battles` (#227) — le pendant exact de
 * {@see SessionDrop} pour le combat, jamais fusionné avec lui : deux origines distinctes
 * (`Training` clôt une séance, `Combat` clôt un combat) qui ne partagent aucun vocabulaire
 * au-delà de ce que {@see DroppedItem} décrit déjà — voir le docblock de {@see BattleDrops}
 * pour pourquoi ce n'est pas une extension de {@see SessionDrop}.
 *
 * **`coinsBefore`/`coinsAfter` sont lus sur le solde réel du joueur, jamais reconstitués à
 * partir de `coinsGained`.** Même règle et même raison que sur {@see SessionDrop} : un
 * rejeu ou une écriture concurrente pourrait faire diverger `before + gained` du vrai solde
 * sans que personne ne le remarque.
 *
 * **Vide n'est pas absent.** {@see none()} produit la forme qu'une défaite, un `max_turns`
 * sans KO, ou un adversaire sans table dédiée doit quand même porter : aucun objet, aucun
 * gain, mais un solde réel et identique avant et après.
 */
final readonly class BattleDrop
{
    /**
     * @param list<DroppedItem> $items vide pour une défaite, ou si l'adversaire n'a pas de table dédiée
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
