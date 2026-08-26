<?php

declare(strict_types=1);

namespace App\Progression\UI\Http\Response;

use App\Progression\Domain\XpBreakdownLine;
use App\Progression\Domain\XpTransaction;
use App\Shared\Domain\Activity\AttributeGains;
use DateTimeInterface;

/**
 * Une écriture du ledger telle que le client la reçoit, **avec son détail**.
 *
 * C'est tout l'intérêt de l'écran : « d'où vient mon XP » ne se répond pas par un montant.
 * Le breakdown stocké redit ligne par ligne ce que le calcul avait décidé — le socle, ce
 * que les rendements décroissants ont rogné, ce que la série a ajouté — et il le redit
 * **sous les règles du jour du calcul**, que `rulesetVersion` nomme. Un rééquilibrage ne
 * réécrit donc pas l'histoire.
 *
 * `sourceId` et non `sessionId` : c'est le nom du ledger, qui n'a jamais promis que ce
 * serait une séance. Ça l'est en v1, et ça ne le sera plus le jour où un bonus de ligue en
 * produira une.
 *
 * `amount` est signé, `durationSeconds` aussi : une séance invalidée écrit sa contrepartie
 * négative, et le joueur doit pouvoir la lire pour comprendre pourquoi son total a baissé.
 *
 * **`attributes` (#163) répond à « pourquoi ma Mobility stagne ».** C'est la répartition de
 * `amount` sur les quatre caractéristiques (#159), signée comme le reste : une annulation y
 * montre des valeurs négatives, et elles ne sont pas masquées — c'est la même contrepartie
 * exacte qui explique la baisse. **Pas de `vitality` ici**, contrairement au vocabulaire du
 * `RewardSummary` : Vitality ne reçoit jamais d'écriture, aucune transaction ne lui est
 * adressée — voir le docblock d'`App\Shared\Domain\Activity\Vitality`. La lire ici
 * demanderait de la rederiver à chaque ligne d'une page, sur des totaux qu'elle n'a pas :
 * c'est `GET /api/progression` qui porte son état courant.
 */
final readonly class XpTransactionResource
{
    /**
     * @param list<array{source: string, amount: int}> $breakdown
     */
    private function __construct(
        public string $id,
        public string $sourceId,
        public string $reason,
        public string $discipline,
        public int $amount,
        public int $durationSeconds,
        public array $breakdown,
        public AttributeGains $attributes,
        public string $rulesetVersion,
        public string $occurredAt,
    ) {
    }

    public static function from(XpTransaction $transaction): self
    {
        return new self(
            $transaction->id()->toRfc4122(),
            $transaction->sourceId()->toRfc4122(),
            $transaction->reason()->value,
            $transaction->discipline()->value,
            $transaction->amount(),
            $transaction->durationSeconds(),
            array_map(
                // L'ordre est celui de `position` : le client anime les lignes les unes
                // après les autres, il ne les trie pas.
                static fn (XpBreakdownLine $line): array => ['source' => $line->source->value, 'amount' => $line->amount],
                $transaction->breakdown()->lines,
            ),
            $transaction->attributeGains(),
            $transaction->rulesetVersion(),
            $transaction->occurredAt()->format(DateTimeInterface::ATOM),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'sourceId' => $this->sourceId,
            'reason' => $this->reason,
            'discipline' => $this->discipline,
            'amount' => $this->amount,
            'durationSeconds' => $this->durationSeconds,
            'breakdown' => $this->breakdown,
            // La répartition du même `amount`, par caractéristique plutôt que par source —
            // voir le docblock de la classe.
            'attributes' => [
                'strength' => $this->attributes->strength,
                'endurance' => $this->attributes->endurance,
                'mobility' => $this->attributes->mobility,
                'dexterity' => $this->attributes->dexterity,
            ],
            'rulesetVersion' => $this->rulesetVersion,
            'occurredAt' => $this->occurredAt,
        ];
    }
}
