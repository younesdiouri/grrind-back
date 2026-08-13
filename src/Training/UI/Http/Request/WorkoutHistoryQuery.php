<?php

declare(strict_types=1);

namespace App\Training\UI\Http\Request;

use App\Shared\Domain\Activity\Discipline;
use DateTimeImmutable;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Les paramètres de `GET /api/workouts`, tous facultatifs.
 *
 * Rien n'est validé à la main : le typage suffit, `#[MapQueryString]` transformant le refus
 * du Serializer en 422 nommant le paramètre fautif.
 *
 * Les bornes sont des **instants**, pas des dates : le client envoie son décalage
 * (`2026-07-01T00:00:00+02:00`). Une date nue serait lue à minuit UTC et décalerait la
 * fenêtre d'un joueur parisien de deux heures.
 *
 * `cursor` est une **chaîne opaque** et non plus un UUID — le contrôleur la décode et rend
 * un 422 si elle n'en est pas un. Le client ne la lit jamais, il la renvoie telle quelle.
 */
final readonly class WorkoutHistoryQuery
{
    /** Pour qu'un client ne puisse pas demander tout l'historique en une requête. */
    public const int MAX_LIMIT = 50;

    public function __construct(
        public ?Discipline $discipline = null,
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
        public ?string $cursor = null,
        #[Assert\Range(min: 1, max: self::MAX_LIMIT)]
        public int $limit = 20,
    ) {
    }
}
