<?php

declare(strict_types=1);

namespace App\Rewards\UI\Http\Response;

use App\Rewards\Domain\CoinTransaction;
use DateTimeInterface;

/**
 * Une écriture du ledger de pièces, telle que le client la reçoit — le pendant, en
 * beaucoup plus simple, d'{@see \App\Progression\UI\Http\Response\XpTransactionResource} :
 * pas de `breakdown`, pas de répartition par caractéristique, `amount` **est** l'écriture,
 * voir le docblock de {@see CoinTransaction}.
 *
 * `sourceId` plutôt qu'un identifiant nommé : même raison qu'au ledger d'XP, c'est le nom
 * du ledger, pas celui d'une séance ou d'un combat — `Rewards` n'a même pas de clé
 * étrangère vers l'un ou l'autre pour le dire autrement.
 */
final readonly class CoinTransactionResource
{
    private function __construct(
        public string $id,
        public string $sourceId,
        public string $reason,
        public int $amount,
        public string $occurredAt,
    ) {
    }

    public static function from(CoinTransaction $transaction): self
    {
        return new self(
            $transaction->id()->toRfc4122(),
            $transaction->sourceId()->toRfc4122(),
            $transaction->reason()->value,
            $transaction->amount(),
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
            'amount' => $this->amount,
            'occurredAt' => $this->occurredAt,
        ];
    }
}
