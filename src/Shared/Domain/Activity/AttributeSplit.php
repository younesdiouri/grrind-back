<?php

declare(strict_types=1);

namespace App\Shared\Domain\Activity;

use App\Shared\Application\GameRulesets;
use App\Shared\Domain\RuntimeRuleset;
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
 *
 * ## Une discipline qui ne crédite pas n'a rien à répartir (#167)
 *
 * `WALKING` ne rapporte plus d'XP — voir le docblock de `App\Progression\Domain\XpRates` —
 * donc `distribute()` n'est jamais appelée pour elle : `XpCalculator` s'arrête avant. La
 * couverture exigée ici en tient compte, et le second paramètre du constructeur est **la
 * même liste brute que `XpRates` consomme**, lue dans le snapshot publié et passée telle quelle
 * plutôt que pré-filtrée : les deux tables ne peuvent alors pas diverger sur « qui crédite »
 * sans qu'un seul fichier bouge. Une ligne de répartition pour une discipline qui ne
 * crédite pas serait de la config morte — refusée au même titre qu'une ligne manquante.
 */
final class AttributeSplit
{
    use RuntimeRuleset;
    /** @var array<string, array<string, int>> valeur de discipline → valeur d'attribut → pourcentage */
    private array $percentages;

    /**
     * @param list<array{discipline: string, strength: int, endurance: int, mobility: int, dexterity: int}> $splits
     * @param list<array{discipline: string, credits_xp?: bool}>                                            $disciplines la liste brute de `xp.yaml` — seule `credits_xp` compte ici
     */
    public function __construct(array $splits, array $disciplines, ?GameRulesets $rulesets = null)
    {
        $this->useRuntimeRulesets($rulesets);
        $percentages = [];

        // La lecture de « qui crédite » vit dans son propre objet depuis le #191, où un
        // troisième lecteur est apparu dans un autre module. Rien ne change ici : c'est
        // toujours la liste brute de `xp.yaml` qui entre, et toujours elle qui décide.
        $crediting = new CreditingDisciplines($disciplines);

        foreach ($splits as $split) {
            $discipline = Discipline::tryFrom($split['discipline'])
                ?? throw new InvalidArgumentException(\sprintf('Discipline inconnue à la table de répartition : "%s".', $split['discipline']));

            if (isset($percentages[$discipline->value])) {
                throw new InvalidArgumentException(\sprintf('Discipline en double à la table de répartition : "%s".', $discipline->value));
            }

            // Une ligne pour une discipline qui ne crédite pas ne sera jamais lue par
            // `XpCalculator` : la garder serait de la config morte qui pourrit sans que
            // personne s'en aperçoive.
            if (!$crediting->credits($discipline)) {
                throw new InvalidArgumentException(\sprintf('"%s" ne crédite pas d\'XP, elle ne devrait pas avoir de ligne à la table de répartition.', $discipline->value));
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

        // Une discipline qui crédite et sans ligne rapporterait zéro caractéristique en
        // silence — un joueur découvrirait le trou, pas nous. On préfère ne pas démarrer.
        // Une discipline qui ne crédite pas n'a, à l'inverse, rien à exiger.
        foreach ($crediting->all() as $discipline) {
            if (!isset($percentages[$discipline->value])) {
                throw new InvalidArgumentException(\sprintf('Aucune répartition à la table pour la discipline "%s".', $discipline->value));
            }
        }

        $this->percentages = $percentages;
    }

    public static function runtime(GameRulesets $rulesets): self
    {
        return self::fromSnapshot($rulesets->snapshot(), $rulesets);
    }

    /**
     * Répartit `$amount` entre les quatre caractéristiques selon la discipline pratiquée.
     * Fonction pure, plus fort reste, symétrique sur les négatifs — voir le docblock de la
     * classe pour les trois.
     *
     * @throws InvalidArgumentException appelé pour une discipline qui ne crédite pas — un
     *                                  bug d'appelant, `XpCalculator` ne doit jamais l'atteindre
     */
    public function distribute(Discipline $discipline, int $amount): AttributeGains
    {
        if ($this->isRuntimeRuleset()) {
            return $this->runtimeValue()->distribute($discipline, $amount);
        }

        $percentages = $this->percentages[$discipline->value]
            ?? throw new InvalidArgumentException(\sprintf('"%s" ne crédite pas d\'XP, elle n\'a pas de ligne à la table de répartition.', $discipline->value));

        $magnitude = self::largestRemainder($percentages, abs($amount));

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

    /** @param array<string, mixed> $snapshot */
    private static function fromSnapshot(array $snapshot, ?GameRulesets $rulesets = null): self
    {
        /** @var list<array{discipline: string, credits_xp: bool, split: ?array{strength: int, endurance: int, mobility: int, dexterity: int}}> $disciplines */
        $disciplines = $snapshot['disciplines'];
        /** @var list<array{discipline: string, credits_xp?: bool}> $rates */
        $rates = [];
        /** @var list<array{discipline: string, strength: int, endurance: int, mobility: int, dexterity: int}> $splits */
        $splits = [];
        foreach ($disciplines as $discipline) {
            $rate = ['discipline' => $discipline['discipline']];
            if (!$discipline['credits_xp']) {
                $rate['credits_xp'] = false;
            }
            $rates[] = $rate;
            if (null !== $discipline['split']) {
                $splits[] = ['discipline' => $discipline['discipline'], ...$discipline['split']];
            }
        }

        return new self($splits, $rates, $rulesets);
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
