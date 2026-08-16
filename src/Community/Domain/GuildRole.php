<?php

declare(strict_types=1);

namespace App\Community\Domain;

/**
 * Ce qu'un membre peut faire de sa guilde. Deux valeurs, et c'est délibéré : tout ce
 * qui ressemblerait à une colonne `permissions` ou à une table de droits serait une
 * réécriture de ce que le composant Security fait déjà mieux — la décision se lit dans
 * `GuildVoter`, pas dans une donnée.
 *
 * Le rôle n'est pas un cycle de vie et ne mérite pas de machine à états : la succession
 * du fondateur (#118) se décide sur *l'ensemble* des adhésions — qui est le plus ancien,
 * et faut-il dissoudre — pas sur une transition d'une ligne prise isolément.
 */
enum GuildRole: string
{
    case Founder = 'FOUNDER';
    case Member = 'MEMBER';
}
