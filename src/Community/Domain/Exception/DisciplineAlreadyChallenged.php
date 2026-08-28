<?php

declare(strict_types=1);

namespace App\Community\Domain\Exception;

use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Exception\RuleViolationError;

/**
 * Une Risāla vivante porte déjà cette discipline.
 *
 * Deux raisons, et la seconde suffirait seule : les deux bonus s'additionneraient à +300 %
 * sur le même sport, ce qui n'a jamais été le barème ; et surtout, une Risāla existe pour
 * faire essayer *autre chose* — proposer le sport que la guilde pratique déjà depuis une
 * semaine rate l'intention de la mécanique.
 */
final class DisciplineAlreadyChallenged extends RuleViolationError
{
    public function __construct(Discipline $discipline)
    {
        parent::__construct(
            \sprintf('Une Risāla vivante porte déjà "%s".', $discipline->value),
            ['discipline' => $discipline->value],
        );
    }

    public function type(): string
    {
        return 'discipline-already-challenged';
    }
}
