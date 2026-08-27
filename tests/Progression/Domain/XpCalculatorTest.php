<?php

declare(strict_types=1);

namespace App\Tests\Progression\Domain;

use App\Progression\Domain\DailyLoad;
use App\Progression\Domain\DiminishingReturns;
use App\Progression\Domain\XpBreakdownLine;
use App\Progression\Domain\XpBreakdownSource;
use App\Progression\Domain\XpCalculator;
use App\Progression\Domain\XpRates;
use App\Shared\Domain\Activity\AttributeGains;
use App\Shared\Domain\Activity\AttributeSplit;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Modifier\Modifier;
use App\Shared\Domain\Modifier\ModifierSource;
use App\Shared\Domain\Modifier\ModifierType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le calcul par table de cas. Aucune infra : c'est tout l'intérêt d'une fonction pure, et
 * c'est ce qui permettra de rejouer un calcul de l'an dernier pour expliquer un montant.
 *
 * Le barème de test est fixe et indépendant de celui qui est livré — une heure vaut 90 et
 * non les 60 livrés, ce qui donne au socle les chiffres de l'exemple du produit et fait
 * tomber les troncatures sur des valeurs qui se lisent. Un rééquilibrage ne doit pas casser
 * une table de cas qui parle d'arithmétique.
 */
final class XpCalculatorTest extends TestCase
{
    /**
     * @param list<Modifier>                      $modifiers
     * @param list<array{XpBreakdownSource, int}> $expectedLines
     */
    #[DataProvider('cases')]
    public function testCalculates(int $durationSeconds, array $modifiers, array $expectedLines, int $expectedTotal): void
    {
        $award = self::calculator()->calculate(Discipline::Running, $durationSeconds, $modifiers, DailyLoad::untouched());

        self::assertSame($expectedLines, self::linesOf($award->breakdown->lines));
        self::assertSame($expectedTotal, $award->amount());
    }

    /**
     * @return iterable<string, array{int, list<Modifier>, list<array{XpBreakdownSource, int}>, int}>
     */
    public static function cases(): iterable
    {
        yield 'le socle seul' => [
            3600,
            [],
            [[XpBreakdownSource::Base, 90]],
            90,
        ];

        // L'exemple du ticket : « 90 base, +18 streak, +13 bottes ».
        yield 'streak et objet, additifs sur le socle' => [
            3600,
            [self::bonus(ModifierSource::Streak, 20), self::bonus(ModifierSource::Item, 15)],
            [[XpBreakdownSource::Base, 90], [XpBreakdownSource::Streak, 18], [XpBreakdownSource::Item, 13]],
            121,
        ];

        // Additif, pas multiplicatif : 90 × 1,20 × 1,15 vaudrait 124.
        yield 'la composition n\'est pas multiplicative' => [
            3600,
            [self::bonus(ModifierSource::Streak, 20), self::bonus(ModifierSource::Item, 15)],
            [[XpBreakdownSource::Base, 90], [XpBreakdownSource::Streak, 18], [XpBreakdownSource::Item, 13]],
            121,
        ];

        // Cumulé d'abord, arrondi ensuite : deux troncatures successives auraient donné
        // 9 + 4 = 13 ici, mais 4 + 4 = 8 au lieu de 9 sur un socle de 90 à 5 % + 5 %.
        yield 'deux objets ne comptent que pour une ligne' => [
            3600,
            [self::bonus(ModifierSource::Item, 5), self::bonus(ModifierSource::Item, 5)],
            [[XpBreakdownSource::Base, 90], [XpBreakdownSource::Item, 9]],
            99,
        ];

        yield 'un bonus trop petit pour peser n\'occupe pas de ligne' => [
            600, // socle de 15
            [self::bonus(ModifierSource::Skill, 5)], // 0,75 point, tronqué à 0
            [[XpBreakdownSource::Base, 15]],
            15,
        ];

        yield 'un malus retranche' => [
            3600,
            [self::bonus(ModifierSource::League, -10)],
            [[XpBreakdownSource::Base, 90], [XpBreakdownSource::League, -9]],
            81,
        ];

        // Deux modificateurs d'une même source qui s'annulent ne laissent pas de trace :
        // il n'y a rien à expliquer au joueur.
        yield 'des bonus qui s\'annulent' => [
            3600,
            [self::bonus(ModifierSource::Skill, 10), self::bonus(ModifierSource::Skill, -10)],
            [[XpBreakdownSource::Base, 90]],
            90,
        ];

        yield 'un socle nul n\'accorde aucun bonus' => [
            0,
            [self::bonus(ModifierSource::Streak, 50)],
            [[XpBreakdownSource::Base, 0]],
            0,
        ];

        yield 'la troncature du socle précède les bonus' => [
            3599, // socle de 89, pas 90
            [self::bonus(ModifierSource::Streak, 20)],
            [[XpBreakdownSource::Base, 89], [XpBreakdownSource::Streak, 17]],
            106,
        ];
    }

    /**
     * @param list<array{XpBreakdownSource, int}> $expectedLines
     */
    #[DataProvider('guardedDays')]
    public function testTheGuardRailsShowWhatTheyTrimmed(DailyLoad $today, int $durationSeconds, array $expectedLines, int $expectedTotal): void
    {
        // Le joueur doit comprendre, pas subir : un total amaigri sans ligne qui l'explique
        // ferait passer une mécanique de jeu pour une punition arbitraire.
        $award = self::calculator()->calculate(Discipline::Running, $durationSeconds, [], $today);

        self::assertSame($expectedLines, self::linesOf($award->breakdown->lines));
        self::assertSame($expectedTotal, $award->amount());
    }

    /**
     * @return iterable<string, array{DailyLoad, int, list<array{XpBreakdownSource, int}>, int}>
     */
    public static function guardedDays(): iterable
    {
        // Dans la première tranche, rien n'est rogné : pas de ligne inutile.
        yield 'journée vierge, aucune ligne de garde-fou' => [
            DailyLoad::untouched(),
            2700,
            [[XpBreakdownSource::Base, 67]],
            67,
        ];

        // 50 min déjà faites, séance de 20 : 10 min à 100 %, 10 min à 60 % = 16 min
        // retenues. Socle plein 30, socle retenu 24.
        yield 'à cheval sur deux tranches' => [
            new DailyLoad(50 * 60, 0),
            20 * 60,
            [[XpBreakdownSource::Base, 30], [XpBreakdownSource::Diminishing, -6]],
            24,
        ];

        yield 'au-delà des tranches, la séance ne rapporte plus rien' => [
            new DailyLoad(130 * 60, 0),
            30 * 60,
            [[XpBreakdownSource::Base, 45], [XpBreakdownSource::Diminishing, -45]],
            0,
        ];

        // 150 déjà gagnés sur un plafond de 180 : il reste 30 à prendre sur les 45 gagnés.
        yield 'le plafond quotidien écrête' => [
            new DailyLoad(0, 150),
            30 * 60,
            [[XpBreakdownSource::Base, 45], [XpBreakdownSource::DailyCap, -15]],
            30,
        ];

        // Le plafond écrête, il ne rejette pas — même geste qu'au plafond de durée d'une
        // séance. Le joueur au plafond voit tout partir, et la ligne le dit.
        yield 'déjà au plafond' => [
            new DailyLoad(0, 180),
            30 * 60,
            [[XpBreakdownSource::Base, 45], [XpBreakdownSource::DailyCap, -45]],
            0,
        ];

        // Les deux garde-fous se cumulent, chacun avec sa ligne. 80 min déjà faites, séance
        // de 20 min : elle traverse deux tranches — 10 min à 60 % puis 10 min à 30 %, soit
        // 9 min retenues. Socle plein 30, retenu 13. Et il ne reste que 10 XP sous le
        // plafond, d'où −3 de plus.
        yield 'les deux garde-fous à la fois' => [
            new DailyLoad(80 * 60, 170),
            20 * 60,
            [
                [XpBreakdownSource::Base, 30],
                [XpBreakdownSource::Diminishing, -17],
                [XpBreakdownSource::DailyCap, -3],
            ],
            10,
        ];
    }

    /**
     * Le cas du produit : « 45 de base, +62 pour tes 6,2 km ». Deux lignes d'animation, pas
     * un total unique — c'est ce que la distance achète.
     */
    public function testTheDistanceAddsItsOwnLine(): void
    {
        $award = self::calculator()->calculate(Discipline::Running, 30 * 60, [], DailyLoad::untouched(), distanceMeters: 6200);

        self::assertSame(
            [[XpBreakdownSource::Base, 45], [XpBreakdownSource::Distance, 62]],
            self::linesOf($award->breakdown->lines),
        );
        self::assertSame(107, $award->amount());
    }

    /**
     * Le dénivelé n'est déclaré que sur la randonnée, où il *est* l'effort.
     */
    public function testTheElevationAddsItsOwnLineWhereTheDisciplineDeclaresIt(): void
    {
        $award = self::calculator()->calculate(
            Discipline::Hiking,
            2 * 3600,
            [],
            DailyLoad::untouched(),
            distanceMeters: 9000,
            elevationGainMeters: 640,
        );

        self::assertSame(
            [
                // Deux heures pleines de socle, rognées par les tranches : 60 min à 100 %,
                // 30 à 60 % et 30 à 30 %, soit 87 min retenues sur 120.
                [XpBreakdownSource::Base, 180],
                [XpBreakdownSource::Diminishing, -50],
                [XpBreakdownSource::Distance, 72],
                [XpBreakdownSource::Elevation, 128],
            ],
            self::linesOf($award->breakdown->lines),
        );
    }

    /**
     * Une discipline sans seconde dimension fiable n'en reçoit pas, même si la montre a
     * envoyé quelque chose : le barème décide, pas l'appareil. Sans ça, un joueur au
     * bracelet bavard jouerait à un autre jeu que son voisin.
     */
    public function testADisciplineWithoutADistanceRateIgnoresWhatTheWatchMeasured(): void
    {
        $award = self::calculator()->calculate(
            Discipline::Strength,
            3600,
            [],
            DailyLoad::untouched(),
            distanceMeters: 4000,
            elevationGainMeters: 300,
        );

        self::assertSame([[XpBreakdownSource::Base, 90]], self::linesOf($award->breakdown->lines));
    }

    /**
     * « Non mesuré » et « mesuré, et nul » se traitent pareil **côté lignes** : ni l'un ni
     * l'autre n'occupe une ligne d'animation. « +0 XP pour tes 0 km » à un joueur qui vient
     * de soulever de la fonte n'explique rien.
     */
    public function testNeitherAnUnmeasuredNorAZeroMetricProducesALine(): void
    {
        $unmeasured = self::calculator()->calculate(Discipline::Running, 3600, [], DailyLoad::untouched());
        $flat = self::calculator()->calculate(Discipline::Hiking, 3600, [], DailyLoad::untouched(), elevationGainMeters: 0);

        self::assertSame([[XpBreakdownSource::Base, 90]], self::linesOf($unmeasured->breakdown->lines));
        self::assertSame([[XpBreakdownSource::Base, 90]], self::linesOf($flat->breakdown->lines));
    }

    /**
     * Une distance trop courte pour valoir un point n'occupe pas de ligne non plus — même
     * règle qu'un bonus trop petit pour peser. 80 mètres à 10 XP le kilomètre tronquent à
     * zéro.
     */
    public function testADistanceTooShortToBeWorthAPointDoesNotProduceALine(): void
    {
        $award = self::calculator()->calculate(Discipline::Running, 600, [], DailyLoad::untouched(), distanceMeters: 80);

        self::assertSame([[XpBreakdownSource::Base, 15]], self::linesOf($award->breakdown->lines));
    }

    /**
     * Le terrain n'est **pas** raboté par les rendements décroissants : dix kilomètres
     * restent dix kilomètres quelle que soit l'heure à laquelle on les a courus. C'est le
     * plafond quotidien qui borne ce côté-là, et il le fait plus bas dans la séquence.
     */
    public function testTheTerrainEscapesTheDiminishingReturnsButNotTheDailyCap(): void
    {
        // 130 min déjà faites : le socle est entièrement rogné. La distance, elle, tient.
        $award = self::calculator()->calculate(
            Discipline::Running,
            30 * 60,
            [],
            new DailyLoad(130 * 60, 0),
            distanceMeters: 5000,
        );

        self::assertSame(
            [
                [XpBreakdownSource::Base, 45],
                [XpBreakdownSource::Diminishing, -45],
                [XpBreakdownSource::Distance, 50],
            ],
            self::linesOf($award->breakdown->lines),
        );
        self::assertSame(50, $award->amount());
    }

    /**
     * Les bonus en pourcentage portent sur le socle rogné, **terrain non compris**. Sinon
     * un même streak vaudrait trois fois plus sur un trail que sur une séance de fonte,
     * pour une raison que personne ne pourrait raconter.
     */
    public function testTheBonusesDoNotApplyToTheTerrain(): void
    {
        $award = self::calculator()->calculate(
            Discipline::Running,
            3600,
            [self::bonus(ModifierSource::Streak, 20)],
            DailyLoad::untouched(),
            distanceMeters: 3000,
        );

        self::assertSame(
            [
                [XpBreakdownSource::Base, 90],
                [XpBreakdownSource::Distance, 30],
                // 20 % de 90, pas de 120.
                [XpBreakdownSource::Streak, 18],
            ],
            self::linesOf($award->breakdown->lines),
        );
    }

    public function testTheBonusesApplyToTheTrimmedBase(): void
    {
        // 50 min déjà faites : socle plein 30, retenu 24. Le streak porte sur 24, pas sur
        // 30 — sinon un bonus rendrait le rendement décroissant contournable.
        $award = self::calculator()->calculate(Discipline::Running, 20 * 60, [
            self::bonus(ModifierSource::Streak, 50),
        ], new DailyLoad(50 * 60, 0));

        self::assertSame(
            [
                [XpBreakdownSource::Base, 30],
                [XpBreakdownSource::Diminishing, -6],
                [XpBreakdownSource::Streak, 12],
            ],
            self::linesOf($award->breakdown->lines),
        );
        self::assertSame(36, $award->amount());
    }

    public function testTheDailyCapCountsWhatWasAlreadyEarnedInThatDisciplineOnly(): void
    {
        // Le plafond est par discipline : avoir saturé la course n'entame pas la natation.
        $award = self::calculator()->calculate(Discipline::Swimming, 30 * 60, [], new DailyLoad(0, 0));

        self::assertSame([[XpBreakdownSource::Base, 45]], self::linesOf($award->breakdown->lines));
    }

    public function testIgnoresAModifierScopedToAnotherDiscipline(): void
    {
        // Des bottes de course ne servent à rien en natation, et le calcul n'a pas eu à
        // connaître les objets pour le savoir.
        $award = self::calculator()->calculate(Discipline::Swimming, 3600, [
            new Modifier(ModifierType::XpMultiplier, 20, ModifierSource::Item, Discipline::Running),
        ], DailyLoad::untouched());

        self::assertSame([[XpBreakdownSource::Base, 90]], self::linesOf($award->breakdown->lines));
    }

    public function testAppliesAModifierScopedToTheSessionDiscipline(): void
    {
        $award = self::calculator()->calculate(Discipline::Running, 3600, [
            new Modifier(ModifierType::XpMultiplier, 20, ModifierSource::Item, Discipline::Running),
        ], DailyLoad::untouched());

        self::assertSame(108, $award->amount());
    }

    public function testIgnoresAModifierOfAnotherType(): void
    {
        // Le vocabulaire est unique, donc le resolver rendra un ensemble mêlé : c'est au
        // consommateur de ne prendre que ce qui le concerne.
        $award = self::calculator()->calculate(Discipline::Running, 3600, [
            new Modifier(ModifierType::LootLuck, 50, ModifierSource::Item),
            new Modifier(ModifierType::StreakShield, 1, ModifierSource::Streak),
        ], DailyLoad::untouched());

        self::assertSame([[XpBreakdownSource::Base, 90]], self::linesOf($award->breakdown->lines));
    }

    public function testTheBreakdownDoesNotDependOnTheOrderOfTheModifiers(): void
    {
        // Sans ça, deux calculs identiques écriraient deux lignes de ledger différentes.
        $shuffled = self::calculator()->calculate(Discipline::Running, 3600, [
            self::bonus(ModifierSource::League, 10),
            self::bonus(ModifierSource::Item, 15),
            self::bonus(ModifierSource::Streak, 20),
        ], DailyLoad::untouched());

        $ordered = self::calculator()->calculate(Discipline::Running, 3600, [
            self::bonus(ModifierSource::Streak, 20),
            self::bonus(ModifierSource::Item, 15),
            self::bonus(ModifierSource::League, 10),
        ], DailyLoad::untouched());

        self::assertSame(self::linesOf($ordered->breakdown->lines), self::linesOf($shuffled->breakdown->lines));
        self::assertSame(
            [
                [XpBreakdownSource::Base, 90],
                [XpBreakdownSource::Streak, 18],
                [XpBreakdownSource::Item, 13],
                [XpBreakdownSource::League, 9],
            ],
            self::linesOf($ordered->breakdown->lines),
        );
    }

    public function testCarriesTheRulesetVersionItWasCalculatedUnder(): void
    {
        // Le montant et la version voyagent ensemble : une écriture au ledger ne peut pas
        // être datée des règles d'un autre calcul.
        self::assertSame('v1-abcdef012345', self::calculator()->calculate(Discipline::Running, 3600, [], DailyLoad::untouched())->rulesetVersion);
    }

    /**
     * L'invariant du #159 : `attributeGains.total() === amount()`, y compris quand le
     * plafond quotidien a écrêté le montant.
     *
     * La table de test somme 33/33/17/17 — aucun pourcentage rond, pour qu'une répartition
     * calculée sur le mauvais montant ne puisse pas retomber par hasard sur le bon total.
     * Si `distribute()` était appelé sur le montant gagné avant écrêtage (45, ici) plutôt
     * que sur le total final (30), le vecteur rendu sommerait à 45 et non à 30 — c'est
     * précisément ce que ce test refuserait de laisser passer.
     */
    public function testAttributesAreDistributedOnTheAmountAfterTheDailyCapNotBeforeIt(): void
    {
        $calculator = new XpCalculator(self::rates(), self::diminishing(), self::splitOf(33, 33, 17, 17), 'v1-abcdef012345');

        // 150 déjà gagnés sur un plafond de 180 : la séance de 30 min gagnerait 45, mais il
        // ne reste que 30 sous le plafond — le même cas que « le plafond quotidien écrête »
        // ci-dessus.
        $award = $calculator->calculate(Discipline::Running, 30 * 60, [], new DailyLoad(0, 150));

        self::assertSame(30, $award->amount());
        self::assertSame($award->amount(), $award->attributeGains->total());
        self::assertEquals(new AttributeGains(10, 10, 5, 5), $award->attributeGains);
    }

    /**
     * Même preuve à l'autre bout de l'échelle : un socle entièrement rogné par les
     * rendements décroissants doit distribuer zéro sur les quatre, pas le socle plein.
     */
    public function testAttributesAreDistributedOnTheAmountAfterDiminishingReturnsHaveEmptiedIt(): void
    {
        $calculator = new XpCalculator(self::rates(), self::diminishing(), self::splitOf(33, 33, 17, 17), 'v1-abcdef012345');

        $award = $calculator->calculate(Discipline::Running, 30 * 60, [], new DailyLoad(130 * 60, 0));

        self::assertSame(0, $award->amount());
        self::assertEquals(new AttributeGains(0, 0, 0, 0), $award->attributeGains);
    }

    private static function splitOf(int $strength, int $endurance, int $mobility, int $dexterity): AttributeSplit
    {
        return new AttributeSplit(
            array_map(
                static fn (Discipline $discipline): array => [
                    'discipline' => $discipline->value,
                    'strength' => $strength,
                    'endurance' => $endurance,
                    'mobility' => $mobility,
                    'dexterity' => $dexterity,
                ],
                Discipline::cases(),
            ),
            self::everyDisciplineCredits(),
        );
    }

    /**
     * Toutes les disciplines créditent, dans le barème de test — même `WALKING` : ces
     * tests portent sur l'arithmétique de `XpCalculator`, jamais atteinte pour une
     * discipline réelle qui ne crédite pas (#167), donc rien ici n'a à s'en préoccuper.
     *
     * @return list<array{discipline: string}>
     */
    private static function everyDisciplineCredits(): array
    {
        return array_map(
            static fn (Discipline $discipline): array => ['discipline' => $discipline->value],
            Discipline::cases(),
        );
    }

    private static function calculator(): XpCalculator
    {
        return new XpCalculator(self::rates(), self::diminishing(), self::uniformSplit(), 'v1-abcdef012345');
    }

    private static function rates(): XpRates
    {
        // 90 XP l'heure plutôt que les 60 livrés : le socle d'une heure vaut alors 90, ce
        // qui donne au breakdown les chiffres de l'exemple du produit — « 90 base, +18
        // streak, +13 bottes ». Le barème de test n'a aucune raison de suivre
        // l'équilibrage, et en le suivant il rendrait ces tests illisibles à chaque
        // recalibrage.
        return new XpRates(90, [
            ['discipline' => 'RUNNING', 'daily_cap_xp' => 180, 'xp_per_km' => 10],
            ['discipline' => 'WALKING', 'daily_cap_xp' => 180, 'xp_per_km' => 5],
            ['discipline' => 'CYCLING', 'daily_cap_xp' => 180, 'xp_per_km' => 3],
            ['discipline' => 'SWIMMING', 'daily_cap_xp' => 200, 'xp_per_km' => 50],
            ['discipline' => 'HIKING', 'daily_cap_xp' => 500, 'xp_per_km' => 8, 'xp_per_100m_elevation' => 20],
            // Les quatre sans seconde dimension : aucune montre ne leur en donne une
            // fiable, et c'est ce que ces lignes-là servent à vérifier.
            ['discipline' => 'STRENGTH', 'daily_cap_xp' => 160],
            ['discipline' => 'HIIT', 'daily_cap_xp' => 220],
            ['discipline' => 'MOBILITY', 'daily_cap_xp' => 100],
            ['discipline' => 'CLIMBING', 'daily_cap_xp' => 170],
            ['discipline' => 'FOOTBALL', 'daily_cap_xp' => 150],
            ['discipline' => 'COURT_SPORTS', 'daily_cap_xp' => 150],
            ['discipline' => 'RACKET_SPORTS', 'daily_cap_xp' => 150],
        ]);
    }

    private static function diminishing(): DiminishingReturns
    {
        return new DiminishingReturns([
            ['up_to_minutes' => 60, 'weight_percent' => 100],
            ['up_to_minutes' => 90, 'weight_percent' => 60],
            ['up_to_minutes' => 120, 'weight_percent' => 30],
        ], 0);
    }

    /**
     * Une répartition égale partout : ces tests ne portent pas sur les caractéristiques,
     * l'important est qu'elle ne casse jamais le calcul. {@see testAttributesAreDistributedOnTheAmountAfterTheDailyCapNotBeforeIt}
     * porte une table dédiée, choisie pour distinguer les deux positions possibles de
     * l'appel.
     */
    private static function uniformSplit(): AttributeSplit
    {
        return new AttributeSplit(
            array_map(
                static fn (Discipline $discipline): array => [
                    'discipline' => $discipline->value,
                    'strength' => 25,
                    'endurance' => 25,
                    'mobility' => 25,
                    'dexterity' => 25,
                ],
                Discipline::cases(),
            ),
            self::everyDisciplineCredits(),
        );
    }

    private static function bonus(ModifierSource $source, int $percentage): Modifier
    {
        return new Modifier(ModifierType::XpMultiplier, $percentage, $source);
    }

    /**
     * @param list<XpBreakdownLine> $lines
     *
     * @return list<array{XpBreakdownSource, int}>
     */
    private static function linesOf(array $lines): array
    {
        return array_map(static fn (XpBreakdownLine $line): array => [$line->source, $line->amount], $lines);
    }
}
