<?php

declare(strict_types=1);

namespace App\Training\Application;

use App\Training\Domain\TrainingSession;
use Symfony\Component\Uid\Uuid;

/**
 * Une tranche d'historique et de quoi demander la suivante.
 *
 * Pas de total : le compter obligerait à un second `COUNT(*)` sur toute la table à
 * chaque page, pour une information dont un défilement infini n'a aucun usage. Le
 * client sait qu'il est au bout quand `nextCursor` est `null`.
 */
final readonly class SessionPage
{
    /**
     * @param list<TrainingSession> $sessions
     */
    public function __construct(
        public array $sessions,
        public ?Uuid $nextCursor,
    ) {
    }
}
