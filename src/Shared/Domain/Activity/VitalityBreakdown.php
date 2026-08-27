<?php

declare(strict_types=1);

namespace App\Shared\Domain\Activity;

/**
 * De quoi expliquer le bonus quotidien de {@see Vitality::bonused()} — jamais la Vitality
 * elle-même, qui voyage à côté sous sa forme habituelle (un entier, la même depuis #161).
 *
 * Trois nombres, rendus par {@see Vitality::explain()} : la moyenne d'énergie active sur la
 * fenêtre glissante, la cible qui vaut le bonus plein, et le bonus qu'elle a effectivement
 * produit. Un client qui ne les affiche pas peut les ignorer sans rien perdre ; un joueur
 * qui les regarde comprend pourquoi sa Vitality vient de bouger sans qu'aucune séance n'y
 * soit pour rien (#165).
 */
final readonly class VitalityBreakdown
{
    public function __construct(
        /** L'énergie active moyenne sur la fenêtre — une journée absente y compte pour zéro. */
        public int $windowAverageActiveKcal,
        /** La cible d'équilibrage : l'atteindre en moyenne vaut le bonus plafond. */
        public int $targetActiveKcal,
        /** Le bonus effectivement appliqué, en millièmes — ce que `bonused()` a ajouté au socle. */
        public int $bonusPermille,
    ) {
    }
}
