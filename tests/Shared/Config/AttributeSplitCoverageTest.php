<?php

declare(strict_types=1);

namespace App\Tests\Shared\Config;

use App\Shared\Domain\Activity\AttributeSplit;
use App\Shared\Domain\Activity\Discipline;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * La table livrée, celle de `config/game/v1/attributes.yaml`, contre les neuf disciplines
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
 * couverture, en construisant depuis `game.xp.disciplines` comme `services.yaml` le fait
 * réellement, plutôt que de coder en dur la liste des disciplines créditantes.
 */
final class AttributeSplitCoverageTest extends KernelTestCase
{
    public function testTheShippedVitalityFloorIsTheOneDecidedInReview(): void
    {
        self::bootKernel();

        $floorPermille = self::getContainer()->getParameter('game.attributes.vitality.floor_permille');

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

        $splits = self::getContainer()->getParameter('game.attributes.splits');
        self::assertIsArray($splits);

        foreach ($splits as $split) {
            self::assertIsArray($split);
            self::assertNotSame('WALKING', $split['discipline'] ?? null);
        }
    }

    /**
     * Construit depuis les paramètres du conteneur, exactement comme `services.yaml` le
     * fait — même geste qu'`ActivityTypesCoverageTest`. Que la compilation ait abouti
     * prouve déjà que `AttributeSplitSection` a validé la table sans broncher ; ce test
     * vérifie ce qu'elle vaut.
     */
    private static function shippedSplit(): AttributeSplit
    {
        self::bootKernel();
        $container = self::getContainer();

        $splits = $container->getParameter('game.attributes.splits');
        $disciplines = $container->getParameter('game.xp.disciplines');

        self::assertIsArray($splits);
        self::assertIsArray($disciplines);

        /** @var list<array{discipline: string, strength: int, endurance: int, mobility: int, dexterity: int}> $splits */
        /** @var list<array{discipline: string, credits_xp?: bool}> $disciplines */
        return new AttributeSplit($splits, $disciplines);
    }
}
