<?php

declare(strict_types=1);

namespace App\Progression\UI\Http\Request;

use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Les paramètres de `GET /api/progression/history`, tous facultatifs.
 *
 * Rien n'est validé à la main : le typage suffit, `#[MapQueryString]` transformant le refus
 * du Serializer en 422 nommant le paramètre fautif. Un curseur qui n'est pas un UUID est
 * donc rejeté avant d'atteindre la base.
 *
 * Les mêmes noms et les mêmes bornes qu'à `GET /api/training/sessions` : deux paginations
 * qui divergeraient d'un nom de paramètre coûteraient deux implémentations côté client.
 */
final readonly class XpHistoryQuery
{
    /** Pour qu'un client ne puisse pas demander tout l'historique en une requête. */
    public const int MAX_LIMIT = 50;

    public function __construct(
        public ?Uuid $cursor = null,
        #[Assert\Range(min: 1, max: self::MAX_LIMIT)]
        public int $limit = 20,
    ) {
    }
}
