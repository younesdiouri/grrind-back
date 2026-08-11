<?php

declare(strict_types=1);

namespace App\Progression\Domain;

use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Modifier\Modifier;
use App\Shared\Domain\Modifier\ModifierSource;
use App\Shared\Domain\Modifier\ModifierType;

/**
 * Ce qu'une séance rapporte. **Fonction pure** : mêmes entrées, même sortie, aucune base,
 * aucune horloge, aucun réseau. C'est ce qui permet de la tester par table de cas plutôt
 * que par scénario, et de rejouer un calcul de l'an dernier pour comprendre un montant.
 *
 * ## La règle de composition : additive, sur le socle
 *
 * Chaque modificateur contribue d'un pourcentage **du socle**, pas du sous-total courant.
 * Un socle de 90 avec +20 % de streak et +15 % d'objets donne `90 + 18 + 13 = 121`, et non
 * `90 × 1,20 × 1,15`. Trois raisons, dans cet ordre :
 *
 * 1. **Chaque ligne est vraie isolément.** « +18 grâce à ta série » reste exact quoi qu'il
 *    arrive aux autres lignes. En multiplicatif, la contribution d'un objet dépend de ce
 *    qui a été appliqué avant lui : l'ordre du breakdown deviendrait porteur de sens, et
 *    un joueur qui perd son streak verrait aussi baisser la ligne « objets ».
 * 2. **La croissance est linéaire.** C'est l'empilement multiplicatif qui explose quand
 *    les sources se multiplient, et qui oblige à un plafond ; l'additif se lit et se règle.
 * 3. **Le rééquilibrage est local.** Changer un bonus ne déplace pas les autres.
 *
 * **Pas de plafond global**, donc, et c'est la réponse à la question ouverte du #18. Le
 * déclencheur qui le ferait entrer est écrit : le jour où les arbres de compétences (#31)
 * ouvrent assez de nœuds pour que le total des bonus dépasse +100 %, on mesure et on
 * décide. Les garde-fous de #15 bornent déjà la journée, qui est la vraie surface d'abus.
 *
 * ## Ce que ce calcul ne fait pas encore
 *
 * Les rendements décroissants et le plafond quotidien par discipline (#15) sont des lignes
 * négatives qui s'ajouteront ici, après les bonus. Ils ont besoin du contexte de la
 * journée — donc du fuseau du joueur — que cette signature ne prend pas encore.
 */
final readonly class XpCalculator
{
    public function __construct(
        private XpRates $rates,
        private string $rulesetVersion,
    ) {
    }

    /**
     * @param int            $durationSeconds la durée **retenue**, déjà écrêtée par `Training`
     * @param list<Modifier> $modifiers       l'ensemble actif du joueur, tel que le resolver (#18) le rendra
     */
    public function calculate(Discipline $discipline, int $durationSeconds, array $modifiers): XpAward
    {
        $base = $this->rates->baseFor($discipline, $durationSeconds);
        $lines = [new XpBreakdownLine(XpBreakdownSource::Base, $base)];

        foreach (self::bonusPercentages($discipline, $modifiers) as $source => $percentage) {
            // Le total de la source d'abord, l'arrondi ensuite : deux objets à +10 % et
            // +5 % valent une fois 15 % et non deux troncatures successives, qui
            // perdraient des points au joueur sans que rien ne l'explique.
            $contribution = intdiv($base * $percentage, 100);

            // Une ligne à zéro n'explique rien. Un bonus réel mais trop petit pour peser
            // sur ce socle-là n'a pas à occuper une ligne d'animation.
            if (0 !== $contribution) {
                $lines[] = new XpBreakdownLine(XpBreakdownSource::producedBy(ModifierSource::from($source)), $contribution);
            }
        }

        return new XpAward(new XpBreakdown(...$lines), $this->rulesetVersion);
    }

    /**
     * Les bonus regroupés par source, dans l'ordre de déclaration de `ModifierSource`.
     *
     * Grouper rend le breakdown **déterministe** : le même ensemble de modificateurs donne
     * le même détail, quel que soit l'ordre dans lequel le resolver les a produits. Sans
     * ça, deux calculs identiques écriraient deux lignes de ledger différentes.
     *
     * @param list<Modifier> $modifiers
     *
     * @return array<string, int> valeur de `ModifierSource` → pourcentage cumulé, sans les nuls
     */
    private static function bonusPercentages(Discipline $discipline, array $modifiers): array
    {
        $percentages = [];

        foreach (ModifierSource::cases() as $source) {
            $total = 0;

            foreach ($modifiers as $modifier) {
                if ($modifier->source === $source && $modifier->appliesTo(ModifierType::XpMultiplier, $discipline)) {
                    $total += $modifier->value;
                }
            }

            if (0 !== $total) {
                $percentages[$source->value] = $total;
            }
        }

        return $percentages;
    }
}
