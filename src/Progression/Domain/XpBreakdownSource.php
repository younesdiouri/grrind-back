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
 * cinq sources de bonus correspondent une pour une aux contributeurs du
 * `ModifierResolver` (#18) ; les deux dernières sont des garde-fous, et leur contribution
 * est négative par construction.
 *
 * L'ordre de déclaration est **l'ordre d'animation** : ce que la séance vaut, ce que le
 * terrain ajoute, ce que le personnage ajoute, ce que le groupe ajoute, puis ce que les
 * garde-fous reprennent.
 */
enum XpBreakdownSource: string
{
    /** Le socle : le temps, une minute pour un point. */
    case Base = 'BASE';

    /**
     * Ce que les kilomètres ajoutent, et ce que le dénivelé ajoute. Deux lignes
     * d'animation de plus, et c'est tout l'intérêt : « 45 de base, +62 pour tes 6,2 km »
     * se lit et se joue, là où un total unique se subit.
     *
     * Elles s'ajoutent au socle sans être rabotées par les rendements décroissants : dix
     * kilomètres restent dix kilomètres quelle que soit l'heure à laquelle on les a
     * courus. C'est le plafond quotidien qui borne ce côté-là.
     */
    case Distance = 'DISTANCE';
    case Elevation = 'ELEVATION';

    case Streak = 'STREAK';
    case Item = 'ITEM';
    case Skill = 'SKILL';
    case League = 'LEAGUE';

    /**
     * La guilde (#190) : la Risāla de la semaine, et ce qui viendra s'ajouter à côté
     * d'elle. **Après `League` et pas avant** — l'ordre de déclaration est l'ordre
     * d'animation, et ce que le groupe apporte se joue après ce que le personnage a
     * construit tout seul.
     */
    case Guild = 'GUILD';

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
            ModifierSource::Guild => self::Guild,
        };
    }
}
