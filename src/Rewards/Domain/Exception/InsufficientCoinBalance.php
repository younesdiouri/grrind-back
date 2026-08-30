<?php

declare(strict_types=1);

namespace App\Rewards\Domain\Exception;

use App\Shared\Domain\Exception\RuleViolationError;

/**
 * Une écriture ferait passer le solde d'un joueur sous zéro. La requête qui l'a produite
 * est bien formée, mais l'état du compte la refuse — même famille que
 * `EnemyLevelTooLow` : une règle de jeu, pas un problème d'autorisation, d'où le 422
 * plutôt qu'un 409 ou un 403.
 *
 * **Inatteignable à ce ticket (#225)** : rien n'écrit encore de ligne négative, seul un
 * test la construit pour prouver le garde-fou. Elle prendra sens au Lot 6b, quand la
 * boutique (#229) écrira la première dépense — sur une garantie déjà éprouvée plutôt
 * qu'une intention, voir le docblock de
 * {@see \App\Rewards\Infrastructure\Doctrine\CoinTransactionRepository::record()}.
 */
final class InsufficientCoinBalance extends RuleViolationError
{
    public function __construct(int $balance, int $amount)
    {
        parent::__construct(
            \sprintf(
                'Le solde de %d pièce(s) ne couvre pas une écriture de %d : un solde ne peut pas passer sous zéro.',
                $balance,
                $amount,
            ),
            ['balance' => $balance, 'amount' => $amount],
        );
    }

    public function type(): string
    {
        return 'insufficient-coin-balance';
    }
}
