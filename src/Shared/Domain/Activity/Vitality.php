<?php

declare(strict_types=1);

namespace App\Shared\Domain\Activity;

use App\Shared\Application\GameRulesets;
use App\Shared\Domain\RuntimeRuleset;
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
 *
 * ## Le bonus quotidien : un second facteur, jamais une seconde base (#165)
 *
 * Le coefficient et le plancher ci-dessus répondent à « la pratique est-elle variée » ;
 * `bonused()` répond à une question différente — « la journée a-t-elle été active » — sur
 * l'énergie active moyenne d'une fenêtre glissante :
 *
 *     bonused = base × (1000 + bonusPermille) / 1000
 *
 * **Toujours reçue en paramètre, jamais recherchée ici.** La fonction reste pure : ni
 * fenêtre, ni journée, ni fournisseur ne se lisent dans cette classe. C'est l'appelant —
 * `Progression`, à la lecture — qui interroge le port de `Shared` que `Training` implémente
 * pour obtenir la moyenne, exactement comme {@see of()} reçoit déjà les quatre totaux
 * plutôt que d'aller les chercher au ledger.
 *
 * **Un multiplicateur, jamais un crédit.** `bonused(0, ...)` vaut `0` quel que soit le
 * bonus : `scale()` multiplie par zéro avant de diviser, comme `of()` le fait déjà pour un
 * total nul. Il n'y a pas de garde explicite pour ce cas — il n'y en a pas besoin, c'est
 * l'arithmétique elle-même qui l'impose, et c'est exactement ce qui interdit à ce bonus de
 * créer de la Vitality à partir de rien pour un compte qui vient de s'inscrire.
 *
 * **Jamais au ledger, jamais sur le snapshot.** `ProgressionSnapshot::vitality()` reste la
 * valeur *non* bonifiée — voir son docblock — parce que la fenêtre glissante change à
 * chaque minute qui passe sans qu'aucune séance n'ait eu lieu : la stocker figerait un
 * bonus qui n'a pourtant pas besoin d'écriture pour rester vrai, à l'inverse du reste du
 * snapshot, qui est un cache d'un ledger append-only. `bonused()` et {@see explain()} se
 * rappellent donc à chaque lecture de `/api/progression` et du profil public.
 */
final class Vitality
{
    use RuntimeRuleset;
    private const int PERMILLE = 1000;

    public function __construct(
        private int $floorPermille,
        /** L'énergie active moyenne, sur la fenêtre, qui vaut le bonus plein — voir {@see bonusPermilleFor()}. */
        private int $targetActiveKcal,
        /** Le bonus ne dépasse jamais ce plafond, même très au-delà de la cible. */
        private int $bonusCapPermille,
        ?GameRulesets $rulesets = null,
    ) {
        $this->useRuntimeRulesets($rulesets);
        if ($floorPermille < 0 || $floorPermille > self::PERMILLE) {
            throw new InvalidArgumentException(\sprintf('Le plancher de Vitality s\'exprime en millièmes, entre 0 et 1000 : %d reçu.', $floorPermille));
        }

        if ($targetActiveKcal < 1) {
            throw new InvalidArgumentException(\sprintf('La cible d\'énergie active doit valoir au moins 1 kcal : %d reçu.', $targetActiveKcal));
        }

        // Pas de borne basse à zéro exclu : un bonus coupé à zéro reviendrait à ne pas en
        // avoir, ce qui est une configuration valide, pas une erreur. La borne haute, elle,
        // protège contre un zéro de trop dans le YAML — un bonus qui doublerait la Vitality
        // ferait de la journée active le seul levier du jeu, devant la variété des sports
        // que le coefficient plus haut existe pour récompenser.
        if ($bonusCapPermille < 0 || $bonusCapPermille > self::PERMILLE) {
            throw new InvalidArgumentException(\sprintf('Le plafond de bonus de Vitality s\'exprime en millièmes, entre 0 et 1000 : %d reçu.', $bonusCapPermille));
        }
    }

    public static function runtime(GameRulesets $rulesets): self
    {
        return self::fromSnapshot($rulesets->snapshot(), $rulesets);
    }

    /**
     * La Vitality du joueur, dérivée des quatre totaux qu'il a déjà accumulés — au ledger
     * comme au snapshot, jamais recalculée depuis autre chose.
     */
    public function of(AttributeGains $totals): int
    {
        if ($this->isRuntimeRuleset()) {
            return $this->runtimeValue()->of($totals);
        }

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

    /**
     * La Vitality bonifiée, prête à être affichée — `$base` est {@see of()}, déjà calculée
     * par l'appelant à partir du snapshot ou du ledger.
     */
    public function bonused(int $base, int $windowAverageActiveKcal): int
    {
        if ($this->isRuntimeRuleset()) {
            return $this->runtimeValue()->bonused($base, $windowAverageActiveKcal);
        }

        return self::scale($base, self::PERMILLE + $this->bonusPermilleFor($windowAverageActiveKcal));
    }

    /**
     * De quoi expliquer le bonus au joueur, sans redonner la valeur bonifiée elle-même —
     * {@see bonused()} la porte déjà. Un joueur qui voit sa Vitality monter sans savoir
     * pourquoi ne comprend rien à la mécanique ; ces trois nombres répondent à « pourquoi ».
     */
    public function explain(int $windowAverageActiveKcal): VitalityBreakdown
    {
        if ($this->isRuntimeRuleset()) {
            return $this->runtimeValue()->explain($windowAverageActiveKcal);
        }

        $average = max(0, $windowAverageActiveKcal);

        return new VitalityBreakdown($average, $this->targetActiveKcal, $this->bonusPermilleFor($average));
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

    /**
     * Proportionnel à la cible, plafonné : une journée sans donnée vaut zéro, une journée
     * qui atteint la cible vaut le plafond, et rien au-delà — reproduire la cible deux fois
     * ne double pas le bonus, ce n'est déjà plus ce qui distingue un joueur actif d'un autre.
     */
    private function bonusPermilleFor(int $windowAverageActiveKcal): int
    {
        $average = max(0, $windowAverageActiveKcal);

        return max(0, min($this->bonusCapPermille, intdiv($average * $this->bonusCapPermille, $this->targetActiveKcal)));
    }

    private static function scale(int $total, int $permille): int
    {
        return intdiv($total * $permille, self::PERMILLE);
    }

    /** @param array<string, mixed> $snapshot */
    private static function fromSnapshot(array $snapshot, ?GameRulesets $rulesets = null): self
    {
        /** @var array{vitality: array{floor_permille: int, target_active_kcal: int, bonus_cap_permille: int}} $attributes */
        $attributes = $snapshot['attributes'];
        $vitality = $attributes['vitality'];

        return new self($vitality['floor_permille'], $vitality['target_active_kcal'], $vitality['bonus_cap_permille'], $rulesets);
    }
}
