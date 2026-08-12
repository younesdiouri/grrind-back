<?php

declare(strict_types=1);

namespace App\Progression\Domain;

use App\Shared\Domain\Modifier\ModifierSource;

/**
 * D'où vient une ligne du détail de calcul. C'est le contrat que le client animera —
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

    /**
     * Le vocabulaire du breakdown est un sur-ensemble de celui des modificateurs : le
     * socle et les garde-fous ne viennent d'aucune source active. Le `match` exhaustif
     * est ce qui fait échouer la compilation le jour où un `ModifierSource` s'ajoute
     * sans qu'on ait dit comment il s'affiche.
     */
    public static function producedBy(ModifierSource $source): self
    {
        return match ($source) {
            ModifierSource::Streak => self::Streak,
            ModifierSource::Skill => self::Skill,
            ModifierSource::Item => self::Item,
            ModifierSource::League => self::League,
        };
    }
}
