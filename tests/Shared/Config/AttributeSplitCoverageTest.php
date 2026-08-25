<?php

declare(strict_types=1);

namespace App\Tests\Shared\Config;

use App\Shared\Domain\Activity\AttributeSplit;
use App\Shared\Domain\Activity\Discipline;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * La table livrée, celle de `config/game/v1/attributes.yaml`, contre les neuf disciplines
 * réelles.
 *
 * `AttributeSplitTest` prouve que l'objet tient ses règles sur des données écrites pour
 * lui ; celui-ci prouve que **la table qu'on livre** couvre bien les neuf disciplines et
 * que les trois écarts assumés en revue (#158) sont ceux qui sont réellement livrés.
 */
final class AttributeSplitCoverageTest extends KernelTestCase
{
    /**
     * Les trois écarts confirmés en revue, écrits en toutes lettres dans `attributes.yaml`.
     *
     * @return iterable<string, array{Discipline, int, int, int, int}>
     */
    public static function theConfirmedGaps(): iterable
    {
        // Coquille du document (75/15/5/0, somme à 95) : le point manquant va en Dexterity.
        yield 'la coquille de Strength est corrigée' => [Discipline::Strength, 75, 15, 5, 5];

        // Sans ligne au document : alignée sur la course, Dexterity déplacée vers Mobility.
        yield 'Walking est déduite de Running' => [Discipline::Walking, 5, 80, 10, 5];

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
     */
    public function testTheSumAlwaysEqualsTheDistributedAmountOnEveryShippedDiscipline(): void
    {
        $split = self::shippedSplit();

        foreach (Discipline::cases() as $discipline) {
            self::assertSame(121, $split->distribute($discipline, 121)->total());
            self::assertSame(-121, $split->distribute($discipline, -121)->total());
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

        self::assertIsArray($splits);

        /** @var list<array{discipline: string, strength: int, endurance: int, mobility: int, dexterity: int}> $splits */
        return new AttributeSplit($splits);
    }
}
