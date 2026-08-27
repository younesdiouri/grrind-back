<?php

declare(strict_types=1);

namespace App\Tests\Shared\Domain;

use App\Shared\Domain\Activity\AttributeGains;
use App\Shared\Domain\Activity\Vitality;
use App\Shared\Domain\Activity\VitalityBreakdown;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La table de cas du #161 (le coefficient d'équilibre et son plancher) et du #165 (le
 * bonus quotidien) : ce que `Vitality` rend sur les quatre totaux et une moyenne d'énergie
 * active, sans base ni conteneur.
 *
 * Le plancher, la cible et le plafond utilisés ici sont ceux livrés dans `attributes.yaml`,
 * mais rien dans ce fichier n'en dépend : chaque test les passe explicitement au
 * constructeur, exactement comme `AttributeSplitTest` construit sa propre table plutôt que
 * de lire celle livrée — {@see \App\Tests\Shared\Config\AttributeSplitCoverageTest} joue ce
 * rôle-là pour `Vitality` aussi, par la validation qu'`AttributeSplitSection` fait à la
 * compilation.
 */
final class VitalityTest extends TestCase
{
    private const int FLOOR_PERMILLE = 250;
    private const int TARGET_ACTIVE_KCAL = 500;
    private const int BONUS_CAP_PERMILLE = 200;

    public function testMaximalWhenTheFourAttributesAreEqual(): void
    {
        $vitality = self::vitality();

        // Coefficient à 1 (AM-GM à l'égalité) : la Vitality vaut le total, sans que le
        // plancher n'ait rien à faire.
        self::assertSame(4_000, $vitality->of(new AttributeGains(1_000, 1_000, 1_000, 1_000)));
    }

    public function testTheFloorRescuesAPlayerWhoOnlyPracticesOneSport(): void
    {
        $vitality = self::vitality();

        // Une seule caractéristique porte tout : le produit des quatre est nul, le
        // coefficient s'effondre à zéro, et c'est le plancher — un quart du taux plein —
        // qui protège ce joueur d'une Vitality nulle.
        self::assertSame(1_000, $vitality->of(new AttributeGains(4_000, 0, 0, 0)));
    }

    public function testTheFloorAlsoRescuesTwoStrongAttributesAgainstTwoNullOnes(): void
    {
        $vitality = self::vitality();

        // Deux caractéristiques à zéro suffisent à annuler le produit des quatre, même
        // quand les deux autres sont parfaitement équilibrées entre elles : le coefficient
        // ne fait pas de différence entre « un seul sport » et « deux sports sur quatre »,
        // c'est le même plancher qui rattrape les deux.
        self::assertSame(1_000, $vitality->of(new AttributeGains(2_000, 2_000, 0, 0)));
    }

    public function testANewAccountHasZeroVitalityNotTheFloor(): void
    {
        $vitality = self::vitality();

        // `total = 0` : le plancher protège le joueur monospécialisé, pas la page blanche —
        // voir le ticket. La distinguer du cas précédent est tout l'enjeu du test.
        self::assertSame(0, $vitality->of(new AttributeGains(0, 0, 0, 0)));
    }

    /**
     * Un cas sans aucune ambiguïté d'arrondi : `100/100/25/25` donne une moyenne géométrique
     * de 50 et une moyenne arithmétique de 62,5, un rapport de 0,8 exact — de quoi vérifier
     * que le coefficient récompense un équilibre *partiel*, pas seulement les deux extrêmes
     * couverts par les cas ci-dessus.
     */
    public function testTheCoefficientRewardsPartialBalanceExactly(): void
    {
        $vitality = self::vitality();

        self::assertSame(200, $vitality->of(new AttributeGains(100, 100, 25, 25)));
    }

    /**
     * Le piège du point 1 du ticket : quatre totaux à sept chiffres donnent un produit
     * direct à vingt-huit chiffres, largement au-delà de `PHP_INT_MAX` (dix-neuf). Ici les
     * quatre sont égaux, donc `PHP_INT_MAX` ne protège même pas par accident — un calcul
     * naïf y passerait de plein fouet.
     */
    public function testStaysSoundOnHugeEqualTotalsThatWouldOverflowADirectProduct(): void
    {
        $huge = 9_999_999;

        // Le piège est réel : `$huge ** 4` vaut environ 10^28, largement au-delà de
        // `PHP_INT_MAX` (~9,2 × 10^18) — PHP ne lève pas sur ce dépassement, il convertit
        // silencieusement en flottant, perdant la précision exacte que
        // `sqrt(sqrt(S·E) · sqrt(M·D))` préserve en ne dépassant jamais deux facteurs.
        $vitality = self::vitality();

        // Quatre totaux égaux, fussent-ils énormes : le coefficient reste à 1, la Vitality
        // reste le total.
        self::assertSame($huge * 4, $vitality->of(new AttributeGains($huge, $huge, $huge, $huge)));
    }

    /**
     * Le même piège, avec un total déséquilibré : deux caractéristiques énormes et deux
     * réduites au minimum non nul, pour vérifier que le résultat reste borné entre 0 et le
     * total même à cette échelle, sans jamais approcher un débordement.
     */
    public function testStaysSoundOnHugeSkewedTotalsThatWouldOverflowADirectProduct(): void
    {
        $huge = 9_999_999;
        $total = 2 * $huge + 2;

        $vitality = self::vitality();
        $result = $vitality->of(new AttributeGains($huge, $huge, 1, 1));

        self::assertGreaterThanOrEqual(0, $result);
        self::assertLessThanOrEqual($total, $result);
        // Le plancher domine : un coefficient qui s'effondre presque à zéro sur un total
        // énorme n'a pas de raison de dépasser le quart garanti.
        self::assertSame(intdiv($total * self::FLOOR_PERMILLE, 1000), $result);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidFloors(): iterable
    {
        yield 'négatif' => [-1];
        yield 'au-delà du millier' => [1_001];
    }

    #[DataProvider('invalidFloors')]
    public function testRefusesAFloorThatIsNotExpressedInPermille(int $floorPermille): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Vitality($floorPermille, self::TARGET_ACTIVE_KCAL, self::BONUS_CAP_PERMILLE);
    }

    public function testRefusesATargetOfZeroActiveKcal(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Vitality(self::FLOOR_PERMILLE, 0, self::BONUS_CAP_PERMILLE);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidBonusCaps(): iterable
    {
        yield 'négatif' => [-1];
        yield 'au-delà du millier' => [1_001];
    }

    #[DataProvider('invalidBonusCaps')]
    public function testRefusesABonusCapThatIsNotExpressedInPermille(int $bonusCapPermille): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Vitality(self::FLOOR_PERMILLE, self::TARGET_ACTIVE_KCAL, $bonusCapPermille);
    }

    /**
     * Le point 5 du ticket #165 : le bonus multiplie, il ne crée rien. Un joueur qui vient
     * de s'inscrire a une énergie active moyenne nulle *et* une Vitality de base nulle —
     * les deux zéros n'ont pas la même cause, mais le résultat doit être le même zéro.
     */
    public function testANewAccountStaysAtZeroEvenWithAPerfectBonus(): void
    {
        $vitality = self::vitality();

        self::assertSame(0, $vitality->bonused(0, self::TARGET_ACTIVE_KCAL * 10));
    }

    /**
     * Aucune donnée d'énergie active sur la fenêtre : le bonus est nul, la Vitality
     * bonifiée égale exactement la base — un joueur qui n'envoie jamais rien à
     * `PUT /api/daily-activity` ne doit ni gagner ni perdre par rapport à avant #165.
     */
    public function testNoActiveEnergyMeansNoBonus(): void
    {
        $vitality = self::vitality();

        self::assertSame(1_000, $vitality->bonused(1_000, 0));
        self::assertEquals(new VitalityBreakdown(0, self::TARGET_ACTIVE_KCAL, 0), $vitality->explain(0));
    }

    /**
     * Atteindre pile la cible vaut le plafond du bonus, ni plus ni moins.
     */
    public function testReachingTheTargetGivesExactlyTheCappedBonus(): void
    {
        $vitality = self::vitality();

        self::assertSame(
            1_000 + intdiv(1_000 * self::BONUS_CAP_PERMILLE, 1_000),
            $vitality->bonused(1_000, self::TARGET_ACTIVE_KCAL),
        );
        self::assertSame(self::BONUS_CAP_PERMILLE, $vitality->explain(self::TARGET_ACTIVE_KCAL)->bonusPermille);
    }

    /**
     * Bien au-delà de la cible, le bonus reste plafonné — répéter la cible deux fois ne
     * double pas le bonus.
     */
    public function testTheBonusNeverExceedsItsCap(): void
    {
        $vitality = self::vitality();

        self::assertSame(self::BONUS_CAP_PERMILLE, $vitality->explain(self::TARGET_ACTIVE_KCAL * 10)->bonusPermille);
    }

    /**
     * À mi-cible, le bonus vaut la moitié du plafond — la proportionnalité du #165, sur un
     * cas sans ambiguïté d'arrondi.
     */
    public function testAHalfwayAverageGivesHalfTheCappedBonus(): void
    {
        $vitality = self::vitality();

        self::assertSame(intdiv(self::BONUS_CAP_PERMILLE, 2), $vitality->explain(self::TARGET_ACTIVE_KCAL / 2)->bonusPermille);
    }

    private static function vitality(): Vitality
    {
        return new Vitality(self::FLOOR_PERMILLE, self::TARGET_ACTIVE_KCAL, self::BONUS_CAP_PERMILLE);
    }
}
