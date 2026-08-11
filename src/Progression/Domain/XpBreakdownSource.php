<?php

declare(strict_types=1);

namespace App\Progression\Domain;

/**
 * D'où vient une ligne du détail de calcul. C'est le contrat que le client iOS animera —
 * « 90 base, +18 streak, +13 bottes » — et la colonne `source` de `xp_transaction_line`.
 *
 * Fermé et stocké : ajouter une valeur est une migration de données autant qu'une
 * évolution de code, puisque l'historique restera lisible avec l'ancien vocabulaire. Les
 * quatre sources de bonus correspondent une pour une aux contributeurs du
 * `ModifierResolver` (#18) ; les deux dernières sont des garde-fous, et leur contribution
 * est négative par construction.
 */
enum XpBreakdownSource: string
{
    /** Le socle : ce que la séance vaut avant tout modificateur. */
    case Base = 'BASE';

    case Streak = 'STREAK';
    case Item = 'ITEM';
    case Skill = 'SKILL';
    case League = 'LEAGUE';

    /** Les rendements décroissants de la journée (#15) : négatif au crédit. */
    case Diminishing = 'DIMINISHING';

    /** Le plafond quotidien par discipline (#15) : négatif au crédit. */
    case DailyCap = 'DAILY_CAP';
}
