<?php

declare(strict_types=1);

namespace App\Progression\UI\Http\Response;

use App\Progression\Application\XpHistoryPage;

/**
 * L'enveloppe n'est pas décorative : un tableau nu à la racine interdirait d'ajouter quoi
 * que ce soit plus tard sans casser le décodage du client.
 *
 * `nextCursor` à `null` signifie « plus rien après » — le client s'arrête là, sans total.
 * Même forme qu'à `GET /api/training/sessions`, au nom de la clé de liste près.
 */
final readonly class XpHistoryPageResource
{
    /**
     * @param list<XpTransactionResource> $transactions
     */
    private function __construct(
        public array $transactions,
        public ?string $nextCursor,
    ) {
    }

    public static function from(XpHistoryPage $page): self
    {
        return new self(
            array_map(XpTransactionResource::from(...), $page->transactions),
            $page->nextCursor?->toRfc4122(),
        );
    }

    /**
     * @return array{transactions: list<array<string, mixed>>, nextCursor: string|null}
     */
    public function toArray(): array
    {
        return [
            'transactions' => array_map(static fn (XpTransactionResource $t): array => $t->toArray(), $this->transactions),
            'nextCursor' => $this->nextCursor,
        ];
    }
}
