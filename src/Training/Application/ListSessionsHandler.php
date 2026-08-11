<?php

declare(strict_types=1);

namespace App\Training\Application;

use App\Training\Domain\TrainingSession;
use App\Training\Infrastructure\Doctrine\TrainingSessionRepository;

/**
 * Découpe l'historique en pages. Une ligne de plus que demandé est lue à chaque appel :
 * si elle existe, il y a une suite, et le curseur suivant est le dernier élément
 * **rendu**. Un curseur désigne une position dans les données et non un rang — la page
 * ne glisse donc pas quand une séance s'ajoute pendant le défilement.
 */
final readonly class ListSessionsHandler
{
    public function __construct(private TrainingSessionRepository $sessions)
    {
    }

    public function __invoke(ListSessions $query): SessionPage
    {
        $found = $this->sessions->history($query, $query->limit + 1);

        if (\count($found) <= $query->limit) {
            return new SessionPage($found, null);
        }

        // Non vide par construction : plus de lignes que la limite, elle-même >= 1.
        /** @var non-empty-list<TrainingSession> $page */
        $page = \array_slice($found, 0, $query->limit);

        return new SessionPage($page, $page[array_key_last($page)]->id());
    }
}
