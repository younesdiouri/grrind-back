<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Shared\Domain\Activity\Discipline;
use Symfony\Component\Uid\Uuid;

/**
 * Le membre tiré arrête le sport qu'il envoie à sa guilde.
 *
 * Aucun identifiant de tour ni de guilde : on n'a qu'une guilde, elle n'a qu'un tour ouvert,
 * et le joueur courant suffit à les désigner tous les deux. Un paramètre de plus n'ouvrirait
 * aucune possibilité mais donnerait une prise à vérifier — même raison qu'à `LeaveGuild`.
 */
final readonly class ChooseRisala
{
    public function __construct(
        public Uuid $playerId,
        public Discipline $discipline,
    ) {
    }
}
