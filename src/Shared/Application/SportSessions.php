<?php

declare(strict_types=1);

namespace App\Shared\Application;

use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Les séances qui font un jour sportif (#286) : `Training` les possède, `Engagement` en
 * tire la série de régularité sans importer un workout.
 *
 * Seules les séances **créditées** d'une discipline **créditrice** en sortent — la marche
 * ne compte pas, et un workout conservé hors fenêtre d'antériorité non plus : sans quoi
 * trois ans d'Apple Health importés d'un coup ouvriraient trois ans de coffres, exactement
 * ce que la fenêtre interdit déjà pour l'XP.
 */
interface SportSessions
{
    /**
     * Toutes les séances qui comptent du joueur, dans l'ordre chronologique. `seconds` est
     * la durée retenue, écrêtée au plafond comme pour l'XP.
     *
     * @return list<array{id: string, startedAt: DateTimeImmutable, seconds: int}>
     */
    public function of(Uuid $player): array;
}
