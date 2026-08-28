<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Shared\Application\PlayerProfile;
use App\Shared\Domain\Activity\Discipline;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Une Risāla vivante, telle qu'un joueur donné la voit.
 *
 * **`bonusPercent` est résolu pour l'appelant** — 150 s'il la reçoit, 50 s'il l'a envoyée —
 * et non rendu sous forme de deux taux à recomposer. C'est le serveur qui arbitre les valeurs
 * de jeu : un client capable de dériver son propre bonus serait aussi capable de se tromper,
 * et l'écart entre ce qu'il annonce et ce que le ledger crédite serait invisible jusqu'à ce
 * qu'un joueur le signale.
 *
 * L'expéditeur est un {@see PlayerProfile} et non un membre complet : la carte affiche un
 * nom, pas un profil. Il peut d'ailleurs avoir quitté la guilde depuis la révélation — le
 * port des profils le connaît encore, la liste des membres non.
 */
final readonly class RisalaView
{
    public function __construct(
        public Uuid $id,
        public Discipline $discipline,
        public Uuid $senderId,
        public ?PlayerProfile $sender,
        public DateTimeImmutable $revealedAt,
        public DateTimeImmutable $expiresAt,
        public int $bonusPercent,
    ) {
    }
}
