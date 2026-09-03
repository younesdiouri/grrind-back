<?php

declare(strict_types=1);

namespace App\Tests\Shared\Config;

use App\Shared\Domain\Activity\AttributeSplit;
use App\Shared\Domain\Activity\Discipline;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * La table publiée, contre les neuf disciplines
 * réelles qui créditent de l'XP.
 *
 * `AttributeSplitTest` prouve que l'objet tient ses règles sur des données écrites pour
 * lui ; celui-ci prouve que **la table qu'on livre** couvre bien les disciplines
 * créditantes et que les deux écarts assumés en revue (#158) sont ceux qui sont réellement
 * livrés. Le plancher de Vitality (#161), livré dans le même fichier, s'y vérifie aussi :
 * la table de cas complète, elle, vit dans `VitalityTest`.
 *
 * **`WALKING` n'y figure plus (#167).** Elle ne crédite pas d'XP — voir `xp.yaml` — donc
 * `AttributeSplit` n'exige plus de ligne pour elle ; ce test le prouve en même temps que la
 * couverture, en construisant depuis le snapshot publié comme le runtime le fait
 * réellement, plutôt que de coder en dur la liste des disciplines créditantes.
 */
final class AttributeSplitCoverageTest extends KernelTestCase
{
    public function testTheShippedVitalityFloorIsTheOneDecidedInReview(): void
    {
        self::bootKernel();

        /** @var array{attributes: array{vitality: array{floor_permille: int}}} $snapshot */
        $snapshot = self::getContainer()->get(\App\Shared\Application\GameRulesets::class)->snapshot();
        $floorPermille = $snapshot['attributes']['vitality']['floor_permille'];

        // Un quart du taux plein — tranché en revue, voir le docblock de `Vitality` : ni un
        // socle absolu, ni zéro.
        self::assertSame(250, $floorPermille);
    }

    /**
     * Les deux écarts confirmés en revue, écrits en toutes lettres dans `attributes.yaml`.
     *
     * @return iterable<string, array{Discipline, int, int, int, int}>
     */
    public static function theConfirmedGaps(): iterable
    {
        // Coquille du document (75/15/5/0, somme à 95) : le point manquant va en Dexterity.
        yield 'la coquille de Strength est corrigée' => [Discipline::Strength, 75, 15, 5, 5];

        // Sans ligne au document : famille « Fitness / gymnastique ».
        yield 'HIIT reprend la famille Fitness' => [Discipline::Hiit, 20, 25, 25, 30];

        // Reprise de l'exemple d'escalade donné au fil du texte.
        yield 'Climbing reprend l\'exemple du document' => [Discipline::Climbing, 40, 20, 20, 20];
    }

    #[DataProvider('theConfirmedGaps')]
    public function testTheConfirmedGapsAreShipped(Discipline $discipline, int $strength, int $endurance, int $mobility, int $dexterity): void
    {
        $split = self::shippedSplit();
        $gains = $split->distribute($discipline, 100);

        self::assertSame($strength, $gains->strength);
        self::assertSame($endurance, $gains->endurance);
        self::assertSame($mobility, $gains->mobility);
        self::assertSame($dexterity, $gains->dexterity);
    }

    /**
     * L'invariant du ticket sur la table réellement livrée, discipline par discipline, sur
     * un montant qui ne tombe rond pour aucune d'entre elles.
     *
     * `WALKING` n'y entre pas : `distribute()` n'a de sens pour elle dans aucun scénario
     * réel — `XpCalculator` ne l'atteint jamais (#167).
     */
    public function testTheSumAlwaysEqualsTheDistributedAmountOnEveryCreditingDiscipline(): void
    {
        $split = self::shippedSplit();

        foreach (Discipline::cases() as $discipline) {
            if (Discipline::Walking === $discipline) {
                continue;
            }

            self::assertSame(121, $split->distribute($discipline, 121)->total());
            self::assertSame(-121, $split->distribute($discipline, -121)->total());
        }
    }

    /**
     * `WALKING` n'a plus de ligne : la table qu'on livre ne porte pas de config morte
     * pour une discipline qui ne crédite pas.
     */
    public function testWalkingHasNoLineInTheShippedTable(): void
    {
        self::bootKernel();

        /** @var array{disciplines: list<array<string, mixed>>} $snapshot */
        $snapshot = self::getContainer()->get(\App\Shared\Application\GameRulesets::class)->snapshot();
        foreach ($snapshot['disciplines'] as $discipline) {
            self::assertIsArray($discipline);
            if ('WALKING' === ($discipline['discipline'] ?? null)) {
                self::assertNull($discipline['split'] ?? null);
            }
        }
    }

    /**
     * Lit le service runtime, donc la publication qui devra réellement répartir les gains.
     */
    private static function shippedSplit(): AttributeSplit
    {
        self::bootKernel();
        $split = self::getContainer()->get(AttributeSplit::class);
        self::assertInstanceOf(AttributeSplit::class, $split);

        return $split;
    }
}
