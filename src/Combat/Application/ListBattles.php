<?php

declare(strict_types=1);

namespace App\Combat\Application;

use App\Shared\UI\Http\Cursor;
use Symfony\Component\Uid\Uuid;

/**
 * Une page d'historique : les combats du joueur, à partir d'un curseur — le pendant exact de
 * {@see \App\Training\Application\ListWorkouts}, sans filtre : voir le docblock de
 * {@see \App\Combat\UI\Http\ListBattlesController} pour pourquoi.
 *
 * `playerId` vient du jeton, jamais de l'URL — même condition de légitimité que sur
 * `ListWorkouts`.
 */
final readonly class ListBattles
{
    public function __construct(
        public Uuid $playerId,
        public ?Cursor $cursor = null,
        public int $limit = 20,
    ) {
    }
}
