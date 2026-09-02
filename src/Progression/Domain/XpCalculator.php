<?php

declare(strict_types=1);

namespace App\Progression\Domain;

use App\Shared\Application\GameRulesets;
use App\Shared\Domain\Activity\AttributeSplit;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Modifier\Modifier;
use App\Shared\Domain\Modifier\ModifierSource;
use App\Shared\Domain\Modifier\ModifierType;

/**
 * Ce qu'une séance rapporte. **Fonction pure** : mêmes entrées, même sortie, aucune base,
 * aucune horloge, aucun réseau. C'est ce qui permet de la tester par table de cas plutôt
 * que par scénario, et de rejouer un calcul de l'an dernier pour comprendre un montant.
 *
 * ## La formule, en une phrase
 *
 *     une minute = un point, plus les kilomètres, plus le dénivelé.
 *
 * Le socle ne dépend plus de la discipline (#90) : un taux horaire par sport était une
 * calibration inventée avant d'avoir un seul joueur, et elle coûtait six lignes à défendre
 * à chaque discussion d'équilibrage. Ce qui distingue les disciplines est désormais mesuré.
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
 * ## L'ordre des opérations
 *
 * 1. **le socle**, au prorata de la durée retenue ;
 * 2. **les rendements décroissants**, qui rabotent le socle selon ce que le joueur a déjà
 *    accumulé aujourd'hui ;
 * 3. **la distance et le dénivelé**, qui s'ajoutent au socle **sans être rabotés** : ils
 *    mesurent une quantité de terrain, pas du temps, et dix kilomètres restent dix
 *    kilomètres quelle que soit l'heure à laquelle on les a courus ;
 * 4. **les bonus**, en pourcentage du socle **après** rabotage — et non du sous-total
 *    incluant le terrain, sans quoi un même streak vaudrait trois fois plus sur un trail
 *    que sur une séance de fonte, pour une raison que personne ne pourrait raconter ;
 * 5. **le plafond quotidien** de la discipline, qui écrête le total et borne le seul côté
 *    que les rendements décroissants ne bornent pas ;
 * 6. **la répartition en caractéristiques** (#159), qui se pose en tout dernier, sur le
 *    montant final déjà écrêté.
 *
 * Placer les rendements décroissants avant les bonus plutôt qu'après donne exactement le
 * même montant — les deux opérations sont linéaires — mais une arithmétique entière plus
 * simple, une seule troncature au lieu d'un ratio appliqué à un sous-total, et surtout une
 * narration que le joueur suit : « 90 de base, −40 parce que tu as déjà beaucoup couru
 * aujourd'hui, +10 grâce à ta série ».
 *
 * Le breakdown **montre ce qui a été rogné** au lieu de livrer un total amaigri sans
 * explication : c'est ce qui fait la différence entre une mécanique et une punition.
 *
 * ## Pourquoi la répartition se pose en dernier, et pourquoi ça ne change rien
 *
 * `AttributeSplit::distribute()` reçoit `$breakdown->total()` — le montant **après**
 * rendements décroissants et plafond quotidien, jamais le socle. C'est un choix de
 * position, pas seulement de commodité, et il ne bouge pas malgré la tentation de
 * répartir chaque ligne du détail avant de les sommer :
 *
 * - **la composition est additive** (voir plus haut : socle, terrain et bonus s'ajoutent,
 *   ils ne se multiplient jamais) ;
 * - **la distribution est linéaire** — `AttributeSplit` applique un même pourcentage par
 *   caractéristique quel que soit le montant, à l'arrondi de plus fort reste près.
 *
 * La composition d'une somme et d'une fonction linéaire ne dépend pas de l'ordre :
 * répartir chaque ligne puis les sommer donnerait le même vecteur `(S, E, M, D)` que
 * répartir la somme une seule fois — à l'arrondi près, et c'est justement pour *garder*
 * un seul arrondi (plus fort reste, une fois, sur le total) plutôt que d'en cumuler un
 * par ligne que la répartition se pose ici et pas plus tôt. Le plafond quotidien, lui,
 * n'a pas de linéarité à préserver : il **écrête**, donc le répartir avant l'écrêtage
 * distribuerait un montant que le joueur n'a jamais reçu. La position n'est donc pas
 * neutre pour le plafond — c'est précisément pourquoi elle se pose après lui, jamais
 * avant : déplacer cet appel plus haut « pour bien faire » romprait l'invariant
 * `S+E+M+D == amount` sur toute séance écrêtée, sans qu'aucun garde-fou de type ne le
 * voie passer.
 */
final readonly class XpCalculator
{
    public function __construct(
        private XpRates $rates,
        private DiminishingReturns $diminishing,
        private AttributeSplit $attributes,
        private string|GameRulesets $rulesetVersion,
    ) {
    }

    /**
     * @param int            $durationSeconds     la durée **retenue**, déjà écrêtée par `Training`
     * @param list<Modifier> $modifiers           l'ensemble actif du joueur, tel que `ModifierResolver` le rend
     * @param DailyLoad      $today               ce que le joueur a déjà fait dans **sa** journée
     * @param ?int           $distanceMeters      `null` est « non mesuré », jamais zéro
     * @param ?int           $elevationGainMeters idem : aucune montre ne mesure tout
     */
    public function calculate(
        Discipline $discipline,
        int $durationSeconds,
        array $modifiers,
        DailyLoad $today,
        ?int $distanceMeters = null,
        ?int $elevationGainMeters = null,
    ): XpAward {
        $fullBase = $this->rates->baseFor($durationSeconds);
        $base = $this->rates->baseFor($this->diminishing->retain($today->secondsSoFar, $durationSeconds));

        $lines = [new XpBreakdownLine(XpBreakdownSource::Base, $fullBase)];

        if ($base !== $fullBase) {
            $lines[] = new XpBreakdownLine(XpBreakdownSource::Diminishing, $base - $fullBase);
        }

        // Une métrique absente ne produit pas de ligne, et une métrique nulle non plus :
        // « +0 XP pour tes 0 km » à un joueur qui vient de soulever de la fonte n'explique
        // rien. C'est la même règle que pour un bonus trop petit pour peser.
        //
        // Elles sont calculées sur `$distanceMeters` brut, pas sur le socle rogné : dix
        // kilomètres restent dix kilomètres quelle que soit l'heure à laquelle on les a
        // courus. C'est le plafond quotidien qui borne ce côté-là.
        if (0 !== $distance = $this->rates->distanceBonusOf($discipline, $distanceMeters)) {
            $lines[] = new XpBreakdownLine(XpBreakdownSource::Distance, $distance);
        }

        if (0 !== $elevation = $this->rates->elevationBonusOf($discipline, $elevationGainMeters)) {
            $lines[] = new XpBreakdownLine(XpBreakdownSource::Elevation, $elevation);
        }

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

        $breakdown = new XpBreakdown(...$lines);

        if (null !== $overflow = $this->overflowOf($discipline, $breakdown->total(), $today)) {
            $lines[] = $overflow;
            $breakdown = new XpBreakdown(...$lines);
        }

        // En tout dernier, sur le total déjà écrêté par le plafond quotidien — voir le
        // docblock de la classe pour pourquoi cette position ne change rien au vecteur
        // rendu, et pourquoi elle ne peut pas se déplacer avant l'écrêtage.
        $gains = $this->attributes->distribute($discipline, $breakdown->total());

        return new XpAward($breakdown, $gains, $this->rulesetVersion());
    }

    private function rulesetVersion(): string
    {
        return \is_string($this->rulesetVersion) ? $this->rulesetVersion : $this->rulesetVersion->version();
    }

    /**
     * Ce qui dépasse le plafond quotidien de la discipline, en négatif, ou `null` si rien
     * ne dépasse.
     *
     * Le plafond écrête, il ne rejette pas — même geste qu'au plafond de durée d'une
     * séance : le joueur garde ce qu'il peut garder. Et le reste de la journée est déjà
     * consommé, donc un joueur au plafond voit `-tout`, avec la ligne qui le dit.
     */
    private function overflowOf(Discipline $discipline, int $earned, DailyLoad $today): ?XpBreakdownLine
    {
        // Négatif si le plafond a été baissé par un rééquilibrage alors qu'un joueur
        // l'avait déjà dépassé : il ne perd rien de ce qui est au ledger, il ne gagne
        // simplement plus rien aujourd'hui.
        $allowance = max(0, $this->rates->dailyCapOf($discipline) - $today->xpSoFarInDiscipline);

        return $earned > $allowance
            ? new XpBreakdownLine(XpBreakdownSource::DailyCap, $allowance - $earned)
            : null;
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
