<?php

declare(strict_types=1);

namespace App\Shared\Domain\Activity;

use InvalidArgumentException;

/**
 * La table qui répartit un montant d'XP entre les quatre {@see Attribute} selon la
 * {@see Discipline} qui l'a produit — un pourcentage par couple, construite une fois à la
 * compilation du conteneur depuis `config/game/v1/attributes.yaml`. Elle porte les règles
 * de cohérence de la table, et `AttributeSplitSection` les lui fait dire plutôt que de les
 * réécrire — même geste que `XpRates` pour le barème.
 *
 * **La répartition se pose sur le total final, jamais sur le socle.** `XpCalculator`
 * compose additivement : `(socle + Σbonus) × p == socle×p + Σ(bonus×p)`, donc répartir le
 * montant qu'il rend en dernier donne exactement le même vecteur que répartir chaque
 * ligne du détail avant de les sommer. Les garde-fous — rendements décroissants, plafond
 * quotidien — restent sur ce total et n'ont pas à connaître les caractéristiques.
 *
 * ## L'arrondi : plus fort reste, déterministe
 *
 * Quatre troncatures indépendantes (`intdiv($amount * $percent, 100)` pour chacune)
 * perdraient jusqu'à trois points sur un `75/15/5/5` appliqué à 121 : `90 + 18 + 6 + 6 =
 * 120`, pas 121. L'invariant `S+E+M+D == $amount` mourrait en silence, sans qu'aucun type
 * ne s'en aperçoive.
 *
 * La méthode du plus fort reste corrige ça : chaque caractéristique reçoit d'abord sa part
 * tronquée, puis le reliquat — au plus trois points, puisque les pourcentages somment à
 * 100 — va aux caractéristiques dont la troncature a perdu le plus, dans l'ordre. Les ex
 * æquo sont départagés par l'ordre de déclaration de {@see Attribute}, qui est normatif
 * pour cette seule raison : sans lui, deux calculs sur le même montant pourraient diverger
 * selon l'ordre dans lequel PHP itère un tableau.
 *
 * ## La symétrie sur les négatifs
 *
 * Une séance invalidée produit une transaction d'XP négative, et `distribute(-n)` doit
 * rendre l'exact opposé de `distribute(n)` — sinon l'annulation ne solde pas la journée
 * qu'elle annule. La fonction ne calcule donc jamais directement sur un montant négatif :
 * elle répartit `abs($amount)`, une opération pure de signe, puis retourne ce vecteur tel
 * quel ou négate chacune de ses quatre composantes selon le signe d'origine. Les deux
 * appels partagent donc la même répartition en valeur absolue par construction — il ne
 * peut pas exister de cas où ils divergent.
 */
final readonly class AttributeSplit
{
    /** @var array<string, array<string, int>> valeur de discipline → valeur d'attribut → pourcentage */
    private array $percentages;

    /**
     * @param list<array{discipline: string, strength: int, endurance: int, mobility: int, dexterity: int}> $splits
     */
    public function __construct(array $splits)
    {
        $percentages = [];

        foreach ($splits as $split) {
            $discipline = Discipline::tryFrom($split['discipline'])
                ?? throw new InvalidArgumentException(\sprintf('Discipline inconnue à la table de répartition : "%s".', $split['discipline']));

            if (isset($percentages[$discipline->value])) {
                throw new InvalidArgumentException(\sprintf('Discipline en double à la table de répartition : "%s".', $discipline->value));
            }

            $byAttribute = [
                Attribute::Strength->value => $split['strength'],
                Attribute::Endurance->value => $split['endurance'],
                Attribute::Mobility->value => $split['mobility'],
                Attribute::Dexterity->value => $split['dexterity'],
            ];

            $sum = array_sum($byAttribute);

            // Une ligne qui ne somme pas à 100 casserait l'invariant `S+E+M+D == $amount`
            // dès la première séance de cette discipline — mieux vaut ne pas démarrer.
            if (100 !== $sum) {
                throw new InvalidArgumentException(\sprintf('La répartition de "%s" somme à %d %%, pas 100 %%.', $discipline->value, $sum));
            }

            $percentages[$discipline->value] = $byAttribute;
        }

        // Une discipline sans ligne rapporterait zéro caractéristique en silence — un
        // joueur découvrirait le trou, pas nous. On préfère ne pas démarrer.
        foreach (Discipline::cases() as $discipline) {
            if (!isset($percentages[$discipline->value])) {
                throw new InvalidArgumentException(\sprintf('Aucune répartition à la table pour la discipline "%s".', $discipline->value));
            }
        }

        $this->percentages = $percentages;
    }

    /**
     * Répartit `$amount` entre les quatre caractéristiques selon la discipline pratiquée.
     * Fonction pure, plus fort reste, symétrique sur les négatifs — voir le docblock de la
     * classe pour les trois.
     */
    public function distribute(Discipline $discipline, int $amount): AttributeGains
    {
        $magnitude = self::largestRemainder($this->percentages[$discipline->value], abs($amount));

        if ($amount < 0) {
            $magnitude = array_map(static fn (int $share): int => -$share, $magnitude);
        }

        return new AttributeGains(
            $magnitude[Attribute::Strength->value],
            $magnitude[Attribute::Endurance->value],
            $magnitude[Attribute::Mobility->value],
            $magnitude[Attribute::Dexterity->value],
        );
    }

    /**
     * La méthode du plus fort reste, sur un montant déjà positif — le signe est traité par
     * l'appelant, cette fonction n'en a pas connaissance.
     *
     * @param array<string, int> $percentagesByAttribute valeur d'attribut → pourcentage, somme à 100
     *
     * @return array<string, int> valeur d'attribut → part, dont la somme vaut exactement `$amount`
     */
    private static function largestRemainder(array $percentagesByAttribute, int $amount): array
    {
        $shares = [];
        $remainders = [];

        foreach (Attribute::cases() as $attribute) {
            $numerator = $amount * $percentagesByAttribute[$attribute->value];
            $shares[$attribute->value] = intdiv($numerator, 100);
            $remainders[$attribute->value] = $numerator % 100;
        }

        // Au plus 3, puisque les pourcentages somment à 100 : la perte de chaque
        // troncature est inférieure à un point de pourcentage, sur quatre caractéristiques.
        $leftover = $amount - array_sum($shares);

        $byLargestRemainder = Attribute::cases();

        // Tri stable (PHP 8+) : à reste égal, l'ordre de déclaration de `Attribute`
        // départage — c'est ce qui rend le résultat déterministe d'un rejeu à l'autre.
        usort(
            $byLargestRemainder,
            static fn (Attribute $a, Attribute $b): int => $remainders[$b->value] <=> $remainders[$a->value],
        );

        foreach (\array_slice($byLargestRemainder, 0, $leftover) as $attribute) {
            ++$shares[$attribute->value];
        }

        return $shares;
    }
}
