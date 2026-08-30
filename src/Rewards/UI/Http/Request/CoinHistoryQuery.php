<?php

declare(strict_types=1);

namespace App\Rewards\UI\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Les paramètres de `GET /api/inventory/coins` (#30), tous facultatifs — le pendant exact
 * de {@see \App\Combat\UI\Http\Request\BattleHistoryQuery} : aucun filtre, aucun écran n'en
 * réclame.
 *
 * `cursor` est une **chaîne opaque**, décodée par le contrôleur — le client ne la lit
 * jamais, il la renvoie telle quelle.
 */
final readonly class CoinHistoryQuery
{
    /** Pour qu'un client ne puisse pas demander tout l'historique en une requête. */
    public const int MAX_LIMIT = 50;

    public function __construct(
        public ?string $cursor = null,
        #[Assert\Range(min: 1, max: self::MAX_LIMIT)]
        public int $limit = 20,
    ) {
    }
}
