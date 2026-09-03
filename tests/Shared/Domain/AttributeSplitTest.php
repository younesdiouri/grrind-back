<?php

declare(strict_types=1);

namespace App\Tests\Shared\Domain;

use App\Shared\Domain\Activity\AttributeGains;
use App\Shared\Domain\Activity\AttributeSplit;
use App\Shared\Domain\Activity\Discipline;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La table est de l'équilibrage : elle se modifie sans revue de code, donc elle doit se
 * refuser tout seule quand elle n'a pas de sens. Et son invariant — `S+E+M+D` vaut
 * exactement le montant réparti, toujours — n'est prouvé nulle part ailleurs qu'ici : un
 * seul arrondi qui casse ça perd un point d'XP au ledger sans qu'aucun type ne s'en
 * aperçoive.
 */
final class AttributeSplitTest extends TestCase
{
    /**
     * @return iterable<string, array{int, AttributeGains}>
     */
    public static function amountsAgainstA75_15_5_5Split(): iterable
    {
        // Montant nul : rien à répartir, et surtout rien à inventer par arrondi.
        yield 'zéro' => [0, new AttributeGains(0, 0, 0, 0)];

        // Trop petit pour que la part tronquée de qui que ce soit dépasse zéro : tout part
        // au plus fort reste, donc à Strength, le pourcentage le plus haut.
        yield 'un' => [1, new AttributeGains(1, 0, 0, 0)];

        // Les tranchées tombent à 5/1/0/0 (35 = 35 en reste pour Mobility et Dexterity) ;
        // Mobility gagne le reliquat, déclarée avant Dexterity.
        yield 'sept' => [7, new AttributeGains(5, 1, 1, 0)];

        // Le cas du docblock : 90/18/6/6 tronqués perdent un point, que Strength récupère
        // — c'est la ligne dont le reste est le plus grand (75 sur 100).
        yield 'cent vingt et un' => [121, new AttributeGains(91, 18, 6, 6)];

        // L'exact opposé du cas précédent : voir testDistributingANegativeAmountIsTheExactOpposite
        // pour la preuve générique, celui-ci fixe la valeur attendue.
        yield 'moins cent vingt et un' => [-121, new AttributeGains(-91, -18, -6, -6)];
    }

    #[DataProvider('amountsAgainstA75_15_5_5Split')]
    public function testDistributesByLargestRemainder(int $amount, AttributeGains $expected): void
    {
        $gains = self::split(['strength' => 75, 'endurance' => 15, 'mobility' => 5, 'dexterity' => 5])
            ->distribute(Discipline::Strength, $amount);

        self::assertSame($expected->strength, $gains->strength);
        self::assertSame($expected->endurance, $gains->endurance);
        self::assertSame($expected->mobility, $gains->mobility);
        self::assertSame($expected->dexterity, $gains->dexterity);
    }

    /**
     * L'invariant du ticket : la somme des quatre vaut exactement le montant réparti, pour
     * tout montant — y compris négatif. Sur une table adverse, choisie pour qu'aucun
     * pourcentage ne tombe rond.
     */
    #[DataProvider('adversarialAmounts')]
    public function testTheSumAlwaysEqualsTheDistributedAmount(int $amount): void
    {
        $split = self::split(['strength' => 33, 'endurance' => 33, 'mobility' => 17, 'dexterity' => 17]);

        self::assertSame($amount, $split->distribute(Discipline::Strength, $amount)->total());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function adversarialAmounts(): iterable
    {
        yield 'zéro' => [0];
        yield 'un' => [1];
        yield 'sept' => [7];
        yield 'cent vingt et un' => [121];
        yield 'moins cent vingt et un' => [-121];
        yield 'trois' => [3];
        yield 'moins un' => [-1];
    }

    /**
     * Une annulation doit solder exactement la journée qu'elle annule : `distribute(-n)`
     * n'est pas « à peu près l'opposé », il l'est composante par composante.
     */
    #[DataProvider('adversarialAmounts')]
    public function testDistributingANegativeAmountIsTheExactOpposite(int $amount): void
    {
        $split = self::split(['strength' => 40, 'endurance' => 30, 'mobility' => 20, 'dexterity' => 10]);

        $positive = $split->distribute(Discipline::Strength, $amount);
        $negative = $split->distribute(Discipline::Strength, -$amount);

        self::assertSame(-$positive->strength, $negative->strength);
        self::assertSame(-$positive->endurance, $negative->endurance);
        self::assertSame(-$positive->mobility, $negative->mobility);
        self::assertSame(-$positive->dexterity, $negative->dexterity);
    }

    public function testRefusesADisciplineNotCoveredByTheTable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/CLIMBING/');

        $incomplete = array_values(array_filter(
            self::everyDiscipline(),
            static fn (array $split): bool => 'CLIMBING' !== $split['discipline'],
        ));

        new AttributeSplit($incomplete, self::everyDisciplineCredits());
    }

    public function testRefusesADisciplineDeclaredTwice(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/double/');

        new AttributeSplit(
            [
                ...self::everyDiscipline(),
                ['discipline' => 'RUNNING', 'strength' => 25, 'endurance' => 25, 'mobility' => 25, 'dexterity' => 25],
            ],
            self::everyDisciplineCredits(),
        );
    }

    public function testRefusesADisciplineThatDoesNotExist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/QUIDDITCH/');

        new AttributeSplit(
            [
                ...self::everyDiscipline(),
                ['discipline' => 'QUIDDITCH', 'strength' => 25, 'endurance' => 25, 'mobility' => 25, 'dexterity' => 25],
            ],
            self::everyDisciplineCredits(),
        );
    }

    /**
     * Le cas que ce ticket écrit en toutes lettres : une ligne qui ne somme pas à 100
     * ferait mourir l'invariant en silence, sans qu'aucun type ne s'en aperçoive.
     */
    public function testRefusesALineThatDoesNotSumToOneHundred(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/95 %/');

        $withoutRunning = array_values(array_filter(
            self::everyDiscipline(),
            static fn (array $split): bool => 'RUNNING' !== $split['discipline'],
        ));

        new AttributeSplit(
            [
                ...$withoutRunning,
                ['discipline' => 'RUNNING', 'strength' => 75, 'endurance' => 15, 'mobility' => 5, 'dexterity' => 0],
            ],
            self::everyDisciplineCredits(),
        );
    }

    public function testRefusesANegativeComponentEvenWhenTheLineSumsToOneHundred(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/entre 0 et 100/');

        $withoutRunning = array_values(array_filter(
            self::everyDiscipline(),
            static fn (array $split): bool => 'RUNNING' !== $split['discipline'],
        ));

        new AttributeSplit(
            [
                ...$withoutRunning,
                ['discipline' => 'RUNNING', 'strength' => -10, 'endurance' => 80, 'mobility' => 20, 'dexterity' => 10],
            ],
            self::everyDisciplineCredits(),
        );
    }

    /**
     * L'invariant du #167 : une discipline qui ne crédite pas n'a rien à exiger — sa
     * ligne peut manquer sans que la construction échoue.
     */
    public function testADisciplineThatDoesNotCreditIsNotRequiredToHaveALine(): void
    {
        $withoutWalking = array_values(array_filter(
            self::everyDiscipline(),
            static fn (array $split): bool => 'WALKING' !== $split['discipline'],
        ));

        $split = new AttributeSplit($withoutWalking, self::everyDisciplineExcept('WALKING'));

        // La construction n'a pas échoué : c'est la preuve. `distribute()` sur `WALKING`
        // n'a de toute façon aucun sens — `XpCalculator` ne l'atteint jamais (#167).
        self::assertInstanceOf(AttributeSplit::class, $split);
    }

    /**
     * L'autre moitié de l'invariant : une ligne conservée pour une discipline qui ne
     * crédite pas serait de la config morte, refusée au même titre qu'une ligne
     * manquante.
     */
    public function testADisciplineThatDoesNotCreditRefusesALineAnyway(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/WALKING/');

        new AttributeSplit(self::everyDiscipline(), self::everyDisciplineExcept('WALKING'));
    }

    /**
     * @param array{strength: int, endurance: int, mobility: int, dexterity: int} $strengthSplit
     */
    private static function split(array $strengthSplit): AttributeSplit
    {
        $withoutStrength = array_values(array_filter(
            self::everyDiscipline(),
            static fn (array $split): bool => 'STRENGTH' !== $split['discipline'],
        ));

        return new AttributeSplit(
            [
                ['discipline' => 'STRENGTH', ...$strengthSplit],
                ...$withoutStrength,
            ],
            self::everyDisciplineCredits(),
        );
    }

    /**
     * Une table de test, indépendante de celle qui est livrée : un rééquilibrage ne doit
     * pas casser une table de cas qui parle d'arithmétique. Répartition égale partout —
     * seule `Strength`, ci-dessus, porte le cas testé.
     *
     * @return list<array{discipline: string, strength: int, endurance: int, mobility: int, dexterity: int}>
     */
    private static function everyDiscipline(): array
    {
        return array_map(
            static fn (Discipline $discipline): array => [
                'discipline' => $discipline->value,
                'strength' => 25,
                'endurance' => 25,
                'mobility' => 25,
                'dexterity' => 25,
            ],
            Discipline::cases(),
        );
    }

    /**
     * La liste brute de `xp.yaml` que ce test simule : toutes les disciplines créditent.
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

    /**
     * La même liste, avec une discipline explicitement marquée `credits_xp: false` —
     * le pendant de `WALKING` dans `xp.yaml`.
     *
     * @return list<array{discipline: string, credits_xp?: bool}>
     */
    private static function everyDisciplineExcept(string $nonCredited): array
    {
        return array_map(
            static fn (Discipline $discipline): array => $discipline->value === $nonCredited
                ? ['discipline' => $discipline->value, 'credits_xp' => false]
                : ['discipline' => $discipline->value],
            Discipline::cases(),
        );
    }
}
