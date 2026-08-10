<?php

declare(strict_types=1);

namespace App\Training\Application;

use App\Shared\Domain\Activity\Discipline;
use App\Training\Domain\SessionStatus;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Une page d'historique demandée par un joueur : les siennes, filtrées, à partir d'un
 * curseur.
 *
 * `userId` n'est pas un filtre parmi d'autres — c'est la condition qui rend la requête
 * légitime. Il vient du jeton, jamais de l'URL, et aucun chemin ne construit cette
 * commande sans lui.
 *
 * La fenêtre de dates porte sur `startedAt`, le fait sportif, et non sur `createdAt` :
 * le joueur cherche « mes séances de juillet », pas les lignes écrites en juillet. Les
 * deux coïncident au chronomètre et divergeront le jour où une activité s'importera
 * après coup.
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
