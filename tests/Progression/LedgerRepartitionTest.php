<?php

declare(strict_types=1);

namespace App\Tests\Progression;

use App\Progression\Domain\XpAward;
use App\Progression\Domain\XpBreakdown;
use App\Progression\Domain\XpBreakdownLine;
use App\Progression\Domain\XpBreakdownSource;
use App\Progression\Domain\XpTransaction;
use App\Progression\Infrastructure\Doctrine\XpTransactionRepository;
use App\Shared\Domain\Activity\AttributeGains;
use App\Shared\Domain\Activity\AttributeSplit;
use App\Shared\Domain\Activity\Discipline;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * L'invariant du #159 contre la vraie base : `strength + endurance + mobility + dexterity
 * === amount`, sur les quatre colonnes réellement écrites, au crédit et à l'annulation.
 *
 * `XpCalculatorTest` prouve la même chose en mémoire, sur la fonction pure ; celui-ci
 * prouve que `XpTransaction` ne perd rien à la persistance et que `reversalOf()` produit
 * l'exact opposé, colonne par colonne — pas un nouveau tirage sur `-amount`, qui pourrait
 * choisir une autre caractéristique au plus fort reste.
 */
final class LedgerRepartitionTest extends KernelTestCase
{
    private XpTransactionRepository $ledger;
    private EntityManagerInterface $entityManager;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $ledger = $container->get(XpTransactionRepository::class);
        self::assertInstanceOf(XpTransactionRepository::class, $ledger);
        $this->ledger = $ledger;

        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $clock = $container->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);
        $this->now = $clock->now();

        $connection = $this->entityManager->getConnection();
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement('TRUNCATE xp_transaction, xp_transaction_line CASCADE');
    }

    public function testTheFourColumnsSumToTheAmountOnCredit(): void
    {
        // Une table adverse, comme dans AttributeSplitTest : aucun pourcentage rond, pour
        // qu'un arrondi cassé ne puisse pas retomber par hasard sur le bon total.
        $gains = self::split()->distribute(Discipline::Running, 121);

        $credit = $this->record(121, $gains);

        self::assertSame(121, $credit->amount());
        self::assertSame(121, $credit->attributeGains()->total());
        self::assertEquals($gains, $credit->attributeGains());
    }

    /**
     * Le cas qui compte le plus : un montant déjà écrêté par le plafond quotidien avant
     * d'atteindre le ledger. C'est là que répartir le total et répartir un socle non
     * écrêté divergeraient — la preuve que `XpCalculator` a bien appliqué la répartition
     * en dernier vit dans `XpCalculatorTest`, celle-ci vérifie que l'écriture qui en
     * résulte tient l'invariant une fois passée par la vraie base.
     */
    public function testTheFourColumnsSumToTheAmountWhenTheDailyCapHasTrimmedIt(): void
    {
        // Un plafond quotidien écrête l'XP gagné (45) à ce qu'il reste (30 ici) : c'est ce
        // montant final, pas le montant gagné, qui doit se répartir.
        $capped = self::split()->distribute(Discipline::Running, 30);

        $credit = $this->record(30, $capped, new XpBreakdownLine(XpBreakdownSource::DailyCap, -15));

        self::assertSame(30, $credit->amount());
        self::assertSame(30, $credit->attributeGains()->total());
    }

    public function testReversalWritesTheExactOppositeOfEachColumn(): void
    {
        $gains = self::split()->distribute(Discipline::Running, 121);
        $credit = $this->record(121, $gains);

        $reversal = XpTransaction::reversalOf($credit);
        $this->ledger->add($reversal);
        $this->ledger->commit();

        self::assertSame(-121, $reversal->amount());
        self::assertSame(-121, $reversal->attributeGains()->total());
        self::assertSame(-$gains->strength, $reversal->attributeGains()->strength);
        self::assertSame(-$gains->endurance, $reversal->attributeGains()->endurance);
        self::assertSame(-$gains->mobility, $reversal->attributeGains()->mobility);
        self::assertSame(-$gains->dexterity, $reversal->attributeGains()->dexterity);

        // La journée annulée se solde à zéro caractéristique par caractéristique, pas
        // seulement en XP total.
        $totals = $this->ledger->attributeTotalsOf($credit->userId());
        self::assertSame(0, $totals->total());
    }

    public function testTheRepositorySumsAttributesInOneQuery(): void
    {
        $userId = Uuid::v7();

        $this->record(90, new AttributeGains(60, 20, 5, 5), userId: $userId);
        $this->record(45, new AttributeGains(30, 10, 3, 2), userId: $userId);
        // Un autre joueur ne doit pas peser dans la somme.
        $this->record(999, new AttributeGains(999, 0, 0, 0));

        $totals = $this->ledger->attributeTotalsOf($userId);

        self::assertSame(90, $totals->strength);
        self::assertSame(30, $totals->endurance);
        self::assertSame(8, $totals->mobility);
        self::assertSame(7, $totals->dexterity);
        self::assertSame(135, $totals->total());
    }

    private function record(int $amount, AttributeGains $gains, ?XpBreakdownLine $trim = null, ?Uuid $userId = null): XpTransaction
    {
        $lines = [new XpBreakdownLine(XpBreakdownSource::Base, $amount - (null !== $trim ? $trim->amount : 0))];

        if (null !== $trim) {
            $lines[] = $trim;
        }

        $transaction = XpTransaction::creditFor(
            $userId ?? Uuid::v7(),
            Uuid::v7(),
            Discipline::Running,
            2700,
            new XpAward(new XpBreakdown(...$lines), $gains, 'v1-000000000000'),
            $this->now,
        );

        $this->ledger->add($transaction);
        $this->ledger->commit();

        return $transaction;
    }

    /** Une table dont aucune ligne ne somme rond, comme dans AttributeSplitTest. */
    private static function split(): AttributeSplit
    {
        return new AttributeSplit(array_map(
            static fn (Discipline $discipline): array => [
                'discipline' => $discipline->value,
                'strength' => 33,
                'endurance' => 33,
                'mobility' => 17,
                'dexterity' => 17,
            ],
            Discipline::cases(),
        ));
    }
}
