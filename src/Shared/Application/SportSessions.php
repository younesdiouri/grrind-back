<?php

declare(strict_types=1);

namespace App\Shared\Application;

use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Les séances qui montent le palier du coffre de la semaine (#346) : `Training` les
 * possède, `Engagement` en tire le palier sans importer un workout.
 *
 * Seules les séances **créditées** d'une discipline **créditrice** en sortent — la marche
 * ne compte pas, et un workout conservé hors fenêtre d'antériorité non plus.
 */
interface SportSessions
{
    /**
     * Le début de chaque séance qui compte, commencée dans `[$from, $until[`.
     *
     * @return list<DateTimeImmutable>
     */
    public function startedBetween(Uuid $player, DateTimeImmutable $from, DateTimeImmutable $until): array;
}
