<?php

declare(strict_types=1);

namespace App\Training\Application;

use App\Shared\Domain\Activity\Discipline;
use App\Shared\UI\Http\Cursor;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Une page d'historique : les workouts du joueur, filtrés, à partir d'un curseur.
 *
 * `userId` n'est pas un filtre parmi d'autres, c'est la condition qui rend la requête
 * légitime — et il vient du jeton, jamais de l'URL.
 *
 * La fenêtre porte sur `startedAt`, le fait sportif, pas sur `createdAt` : le joueur cherche
 * « mes séances de juillet », pas les lignes écrites en juillet. C'est aussi ce qui a rendu
 * le curseur composite nécessaire — voir {@see Cursor}.
 */
final readonly class ListWorkouts
{
    public function __construct(
        public Uuid $userId,
        public ?Discipline $discipline = null,
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
        public ?Cursor $cursor = null,
        public int $limit = 20,
    ) {
    }
}
