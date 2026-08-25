<?php

declare(strict_types=1);

namespace App\Shared\Domain\Activity;

use InvalidArgumentException;

/**
 * La cinquième caractéristique du game design, **entièrement dérivée** des quatre autres :
 * aucune écriture au ledger ne lui est jamais adressée, {@see Attribute} ne la déclare pas,
 * et {@see AttributeGains} ne la porte jamais — voir le docblock des deux pour pourquoi.
 * Vitality mesure l'équilibre d'une pratique, pas un corps : à XP totale égale, varier les
 * sports en donne plus que s'y consacrer à un seul (#161).
 *
 * **Vit dans `Shared`, pas dans `Progression`.** `ProgressionSnapshot` la lit la première,
 * mais le `RewardSummary` de `Training` la lira ensuite (#162) — et c'est une fonction pure
 * des quatre totaux, sans base ni entité d'aucun module. La poser à côté d'`AttributeGains`
 * et d'`AttributeSplit`, qu'elle prolonge sans y toucher, la rend consommable directement
 * par n'importe quel module sans qu'un port ait à envelopper un simple calcul : un port ne
 * se justifie que pour franchir une frontière d'infrastructure ou d'entité, il n'y en a
 * aucune ici.
 *
 * ## Le coefficient : moyenne géométrique sur moyenne arithmétique
 *
 * Tranché en revue, pas au jugé : le rapport de la moyenne géométrique à la moyenne
 * arithmétique des quatre totaux, en millièmes entiers. L'inégalité AM-GM garantit qu'il
 * vaut au plus 1000 (= 1, quand les quatre sont égales) et qu'il s'effondre vers 0 dès
 * qu'une seule caractéristique porte tout — en douceur, contrairement à un `min/max` qui
 * écraserait la Vitality d'un joueur autrement équilibré sur trois caractéristiques à cause
 * d'une seule restée à zéro.
 *
 * **Jamais sur le produit direct des quatre totaux.** Quatre totaux à sept chiffres donnent
 * un produit à vingt-huit chiffres — `PHP_INT_MAX` en fait dix-neuf. PHP ne boucle pas comme
 * en C sur ce dépassement : il promeut silencieusement le résultat en flottant, dont on ne
 * contrôle plus l'erreur sur une valeur de jeu persistée. La forme retenue ne calcule jamais
 * plus de deux facteurs à la fois :
 *
 *     (S·E·M·D)^(1/4) == sqrt(sqrt(S·E) · sqrt(M·D))
 *
 * chaque produit intermédiaire restant de l'ordre du carré d'un seul total, très loin sous
 * `PHP_INT_MAX`. Le résultat est arrondi au millième entier le plus proche avant de sortir
 * de {@see coefficientPermille()} : aucun flottant ne survit au retour de {@see of()}.
 *
 * ## Le plancher : une part du total, pas un socle absolu
 *
 *     vitality = max(total × coefficient, total × plancherPermille) / 1000
 *
 * Un plancher absolu (« au moins 50 ») figerait un joueur monospécialisé à 100 000 XP au
 * même niveau qu'un débutant — revenant à ne pas récompenser sa pratique du tout. Le
 * plancher proportionnel dit la bonne chose : il progresse quand même, quatre fois plus
 * lentement que qui varie. **À l'inscription, `total = 0` donc `vitality = 0`, et c'est
 * correct** : le plancher protège le joueur monospécialisé, pas la page blanche — un socle
 * qui s'appliquerait même à zéro punirait d'avoir commencé plutôt que de protéger d'avoir
 * choisi un seul sport.
 */
final readonly class Vitality
{
    private const int PERMILLE = 1000;

    public function __construct(private int $floorPermille)
    {
        if ($floorPermille < 0 || $floorPermille > self::PERMILLE) {
            throw new InvalidArgumentException(\sprintf('Le plancher de Vitality s\'exprime en millièmes, entre 0 et 1000 : %d reçu.', $floorPermille));
        }
    }

    /**
     * La Vitality du joueur, dérivée des quatre totaux qu'il a déjà accumulés — au ledger
     * comme au snapshot, jamais recalculée depuis autre chose.
     */
    public function of(AttributeGains $totals): int
    {
        // Un total négatif n'a de sens pour aucun joueur réel — voir `LevelCurve::standingAt()`
        // pour le même garde-fou sur le total d'XP. `0` couvre aussi l'inscription : diviser
        // par une moyenne arithmétique nulle plus bas serait de toute façon indéfini.
        $total = max(0, $totals->total());

        if (0 === $total) {
            return 0;
        }

        return max(
            self::scale($total, $this->coefficientPermille($totals)),
            self::scale($total, $this->floorPermille),
        );
    }

    private function coefficientPermille(AttributeGains $totals): int
    {
        // Chaque composante est bornée à zéro avant d'entrer dans un produit : un total par
        // caractéristique négatif n'a de sens que le temps d'une annulation partielle, et le
        // laisser passer inverserait le signe d'un produit sans que rien ne s'en aperçoive.
        $strength = max(0, $totals->strength);
        $endurance = max(0, $totals->endurance);
        $mobility = max(0, $totals->mobility);
        $dexterity = max(0, $totals->dexterity);

        $geometricMean = sqrt(sqrt($strength * $endurance) * sqrt($mobility * $dexterity));
        $arithmeticMean = ($strength + $endurance + $mobility + $dexterity) / 4;

        // AM-GM garantit que le rapport ne dépasse jamais 1 ; le `min` n'est qu'une garde
        // contre l'imprécision flottante à la marge, pas une correction du calcul.
        return max(0, min(self::PERMILLE, (int) round(self::PERMILLE * $geometricMean / $arithmeticMean)));
    }

    private static function scale(int $total, int $permille): int
    {
        return intdiv($total * $permille, self::PERMILLE);
    }
}
