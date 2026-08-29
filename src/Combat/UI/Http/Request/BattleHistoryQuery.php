<?php

declare(strict_types=1);

namespace App\Combat\UI\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Les paramètres de `GET /api/battles`, tous facultatifs — le pendant exact de
 * {@see \App\Training\UI\Http\Request\WorkoutHistoryQuery}, amputé des filtres : aucun écran
 * n'en demande, voir le docblock de {@see \App\Combat\UI\Http\ListBattlesController}.
 *
 * Rien n'est validé à la main : le typage suffit, `#[MapQueryString]` transformant le refus
 * du Serializer en 422 nommant le paramètre fautif.
 *
 * `cursor` est une **chaîne opaque**, décodée par le contrôleur — le client ne la lit jamais,
 * il la renvoie telle quelle.
 */
final readonly class BattleHistoryQuery
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
