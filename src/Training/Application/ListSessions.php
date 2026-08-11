<?php

declare(strict_types=1);

namespace App\Training\Application;

use App\Shared\Domain\Activity\Discipline;
use App\Training\Domain\SessionStatus;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Une page d'historique : les séances du joueur, filtrées, à partir d'un curseur.
 *
 * `userId` n'est pas un filtre parmi d'autres, c'est la condition qui rend la requête
 * légitime — et il vient du jeton, jamais de l'URL.
 *
 * La fenêtre porte sur `startedAt`, le fait sportif, pas sur `createdAt` : le joueur
 * cherche « mes séances de juillet », pas les lignes écrites en juillet.
 */
final readonly class ListSessions
{
    public function __construct(
        public Uuid $userId,
        public ?SessionStatus $status = null,
        public ?Discipline $discipline = null,
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
        public ?Uuid $cursor = null,
        public int $limit = 20,
    ) {
    }
}
