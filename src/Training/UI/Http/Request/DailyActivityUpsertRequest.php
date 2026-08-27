<?php

declare(strict_types=1);

namespace App\Training\UI\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Contrat d'entrée de `PUT /api/daily-activity`.
 *
 * **Un lot, comme l'import de séances**, et pour la même raison : une semaine d'absence
 * rapporte une semaine de journées à revoir, pas une. Borné à trois mois pour qu'une
 * requête ne devienne pas une reprise d'historique en un seul appel — un premier import se
 * pagine côté client, exactement comme `ImportWorkoutsRequest::MAX_WORKOUTS`.
 */
final readonly class DailyActivityUpsertRequest
{
    public const int MAX_DAYS = 90;

    /**
     * @param list<DailyActivityEntryRequest> $days
     */
    public function __construct(
        #[Assert\Valid]
        #[Assert\Count(min: 1, max: self::MAX_DAYS)]
        public array $days = [],
    ) {
    }
}
