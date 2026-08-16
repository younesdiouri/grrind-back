<?php

declare(strict_types=1);

namespace App\Community\Application;

use Symfony\Component\Uid\Uuid;

/**
 * **Aucun identifiant de guilde** : on ne quitte que la sienne. Le prendre en paramètre
 * n'ouvrirait aucune possibilité — on ne peut appartenir qu'à une guilde — mais donnerait
 * une prise à vérifier, donc une occasion de se tromper.
 */
final readonly class LeaveGuild
{
    public function __construct(public Uuid $playerId)
    {
    }
}
