<?php

declare(strict_types=1);

namespace App\Progression\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Une page du ledger : les écritures d'un joueur, les plus récentes d'abord.
 *
 * `userId` n'est pas un filtre parmi d'autres, c'est la condition qui rend la requête
 * légitime — et il vient du jeton, jamais de l'URL.
 *
 * **Aucun filtre**, contrairement à l'historique des séances. L'écran que ça sert est
 * « d'où vient mon XP » : il se lit dans l'ordre, du haut vers le bas. Filtrer par
 * discipline se demandera le jour où quelqu'un le demandera, avec son ticket ; l'ajouter
 * d'avance, c'est un paramètre à documenter, à valider et à indexer pour personne.
 */
final readonly class ListXpHistory
{
    public function __construct(
        public Uuid $userId,
        public ?Uuid $cursor = null,
        public int $limit = 20,
    ) {
    }
}
