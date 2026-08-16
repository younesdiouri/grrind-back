<?php

declare(strict_types=1);

namespace App\Community\Domain\Exception;

use App\Shared\Domain\Exception\ConflictError;

/**
 * Le fondateur ne s'exclut pas lui-même : il part par `leave`.
 *
 * Ce n'est pas une coquetterie d'API. L'exclusion retire une ligne, point ; le départ,
 * lui, sait transmettre la guilde au doyen ou la dissoudre s'il n'y a plus personne. Un
 * fondateur qui s'exclurait laisserait derrière lui une guilde sans fondateur — donc que
 * plus personne ne peut renommer, dissoudre, ni ouvrir à de nouveaux membres.
 *
 * Le message dit la sortie, parce qu'un refus qui n'indique pas le bon chemin est un
 * refus qu'on recommence.
 */
final class FounderCannotExcludeHimself extends ConflictError
{
    public function __construct()
    {
        parent::__construct('Le fondateur ne peut pas s\'exclure lui-même. Quitte la guilde : elle sera transmise au membre le plus ancien, ou dissoute s\'il n\'en reste aucun.');
    }

    public function type(): string
    {
        return 'founder-cannot-exclude-himself';
    }
}
