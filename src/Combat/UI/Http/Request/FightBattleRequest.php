<?php

declare(strict_types=1);

namespace App\Combat\UI\Http\Request;

/**
 * Le corps, facultatif, de `POST /api/battles` (#219).
 *
 * Absent — ou `{"enemy": null}` — le comportement du #212 ne change pas d'un iota : le
 * serveur choisit l'ennemi au niveau du joueur. `enemy` porte n'importe quelle clé du
 * catalogue, boss comme ennemi ordinaire — voir le docblock de
 * {@see \App\Combat\Application\FightBattleHandler} pour cette décision et son effet de
 * bord assumé. Aucune contrainte de format : une clé inconnue ou un niveau insuffisant se
 * refusent dans le domaine, en 422, pas ici — c'est le catalogue qui fait autorité, pas une
 * expression régulière qui devrait le recopier.
 */
final readonly class FightBattleRequest
{
    public function __construct(
        public ?string $enemy = null,
    ) {
    }
}
