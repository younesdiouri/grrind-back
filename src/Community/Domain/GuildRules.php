<?php

declare(strict_types=1);

namespace App\Community\Domain;

use InvalidArgumentException;

/**
 * Ce qu'une guilde peut contenir. De l'équilibrage, pas une constante de classe : la
 * bonne taille d'un groupe est une question de produit qui bougera après les premiers
 * joueurs, et elle se règle dans `config/game/v1/community.yaml` sans toucher au code.
 *
 * L'objet existe pour que le domaine reçoive une valeur *validée* plutôt qu'un entier
 * nu venu d'un YAML : la cohérence se dit une seule fois, ici, et
 * {@see \App\Community\Infrastructure\Config\CommunitySection} la fait rejouer à la
 * compilation du conteneur.
 */
final readonly class GuildRules
{
    public function __construct(public int $maximumMembers)
    {
        // Une guilde d'un seul membre est un profil avec plus d'étapes : le fondateur
        // occupe déjà une place, donc en dessous de deux personne ne peut le rejoindre.
        if ($maximumMembers < 2) {
            throw new InvalidArgumentException(\sprintf('Une guilde doit pouvoir accueillir au moins deux membres, %d demandé.', $maximumMembers));
        }
    }
}
