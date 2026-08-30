<?php

declare(strict_types=1);

namespace App\Rewards\UI\Http\Response;

use App\Rewards\Application\CoinHistoryPage;

/**
 * L'enveloppe de `GET /api/inventory/coins` (#30) — le pendant exact de
 * {@see \App\Combat\UI\Http\Response\BattlePageResource} et d'{@see \App\Progression\UI\Http\Response\XpHistoryPageResource},
 * `balance` en plus : voir le docblock d'`InventoryController` pour pourquoi cette route
 * porte le solde, pas seulement l'historique qui y a mené.
 *
 * `nextCursor` à `null` signifie « plus rien après » — le client s'arrête là, sans total.
 */
final readonly class CoinHistoryPageResource
{
    /**
     * @param list<CoinTransactionResource> $transactions
     */
    private function __construct(
        public int $balance,
        public array $transactions,
        public ?string $nextCursor,
    ) {
    }

    public static function from(int $balance, CoinHistoryPage $page): self
    {
        return new self(
            $balance,
            array_map(CoinTransactionResource::from(...), $page->transactions),
            $page->nextCursor?->encoded(),
        );
    }

    /**
     * @return array{balance: int, transactions: list<array<string, mixed>>, nextCursor: string|null}
     */
    public function toArray(): array
    {
        return [
            'balance' => $this->balance,
            'transactions' => array_map(static fn (CoinTransactionResource $t): array => $t->toArray(), $this->transactions),
            'nextCursor' => $this->nextCursor,
        ];
    }
}
