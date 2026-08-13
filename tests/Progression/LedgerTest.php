<?php

declare(strict_types=1);

namespace App\Tests\Progression;

use App\Progression\Domain\Exception\LedgerIsNotRewritable;
use App\Progression\Domain\XpAward;
use App\Progression\Domain\XpBreakdown;
use App\Progression\Domain\XpBreakdownLine;
use App\Progression\Domain\XpBreakdownSource;
use App\Progression\Domain\XpReason;
use App\Progression\Domain\XpTransaction;
use App\Progression\Infrastructure\Doctrine\XpTransactionRepository;
use App\Shared\Domain\Activity\Discipline;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Le ledger contre la vraie base. Ce qui compte ici ne se démontre pas en mémoire : les
 * garanties tenues par PostgreSQL (l'idempotence) et par l'unité de travail de Doctrine
 * (le refus de réécrire) n'existent qu'une fois le flush passé.
 *
 * Pas de compte ni de séance : le ledger ne porte que des UUID et n'a aucune clé étrangère
 * vers les autres modules — c'est la frontière, et le test la reflète.
 */
final class LedgerTest extends KernelTestCase
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
        // TRUNCATE et non DELETE : le listener append-only ne s'y oppose pas, il ne voit
        // que ce qui passe par l'unité de travail.
        $connection->executeStatement('TRUNCATE xp_transaction, xp_transaction_line CASCADE');
    }

    public function testWritesATransactionAndReadsBackItsBreakdown(): void
    {
        $userId = Uuid::v7();

        $this->record($userId, Uuid::v7(), new XpBreakdown(
            new XpBreakdownLine(XpBreakdownSource::Base, 90),
            new XpBreakdownLine(XpBreakdownSource::Streak, 18),
            new XpBreakdownLine(XpBreakdownSource::Item, 13),
        ));

        $this->entityManager->clear();
        $transaction = $this->ledger->findOneBy(['userId' => $userId]);
        self::assertInstanceOf(XpTransaction::class, $transaction);

        // Le montant n'a pas été fourni : il est la somme de son détail.
        self::assertSame(121, $transaction->amount());
        self::assertSame(XpReason::SessionCompleted, $transaction->reason());

        // Et l'ordre survit à l'aller-retour : c'est lui que le client anime.
        self::assertSame(
            [[XpBreakdownSource::Base, 90], [XpBreakdownSource::Streak, 18], [XpBreakdownSource::Item, 13]],
            array_map(
                static fn (XpBreakdownLine $line): array => [$line->source, $line->amount],
                $transaction->breakdown()->lines,
            ),
        );
    }

    public function testTheTotalIsReconstructibleBySum(): void
    {
        $userId = Uuid::v7();
        $someoneElse = Uuid::v7();

        $this->record($userId, Uuid::v7(), self::breakdownOf(90));
        $this->record($userId, Uuid::v7(), self::breakdownOf(45));
        $this->record($userId, Uuid::v7(), self::breakdownOf(200));
        $this->record($someoneElse, Uuid::v7(), self::breakdownOf(999));

        // C'est la définition du solde. Le snapshot (#16) n'en sera qu'un cache, et c'est
        // cette somme qui fera autorité le jour où les deux divergeront.
        self::assertSame(335, $this->ledger->totalOf($userId));
        self::assertSame(999, $this->ledger->totalOf($someoneElse));
    }

    public function testAnUnknownPlayerOwesNothing(): void
    {
        // Zéro, pas null : un joueur sans écriture a un solde, il est simplement vide.
        self::assertSame(0, $this->ledger->totalOf(Uuid::v7()));
    }

    public function testInvalidationWritesANegativeTransactionInsteadOfErasing(): void
    {
        $userId = Uuid::v7();
        $sessionId = Uuid::v7();

        $credit = $this->record($userId, $sessionId, new XpBreakdown(
            new XpBreakdownLine(XpBreakdownSource::Base, 90),
            new XpBreakdownLine(XpBreakdownSource::Diminishing, -30),
        ));

        $this->ledger->add(XpTransaction::reversalOf($credit));
        $this->ledger->commit();

        self::assertSame(0, $this->ledger->totalOf($userId));
        // Les deux écritures restent : on peut encore dire ce qui a été donné, et repris.
        self::assertCount(2, $this->ledger->findBy(['userId' => $userId]));

        $reversal = $this->ledger->recordedFor($sessionId, XpReason::SessionInvalidated);
        self::assertInstanceOf(XpTransaction::class, $reversal);
        self::assertSame(-60, $reversal->amount());

        // Sous les règles qui avaient accordé les points, pas celles d'aujourd'hui :
        // sinon un rééquilibrage deviendrait une redistribution silencieuse.
        self::assertSame($credit->rulesetVersion(), $reversal->rulesetVersion());
    }

    public function testTheSameSourceCannotBeCreditedTwice(): void
    {
        $sessionId = Uuid::v7();
        $this->record(Uuid::v7(), $sessionId, self::breakdownOf(90));

        // Un client mobile rejoue ses requêtes. Ce n'est pas un SELECT préalable qui
        // l'empêche — deux requêtes concurrentes le passeraient toutes les deux — c'est
        // uniq_xp_transaction_source_reason.
        $this->expectException(UniqueConstraintViolationException::class);

        $this->record(Uuid::v7(), $sessionId, self::breakdownOf(90));
    }

    public function testATransactionCannotBeRewritten(): void
    {
        $transaction = $this->record(Uuid::v7(), Uuid::v7(), self::breakdownOf(90));

        // L'entité n'a aucun mutateur : il faut la réflexion pour seulement tenter le
        // coup. C'est bien le chemin qui reste — une désérialisation, un `remove()` en
        // cascade — que le listener ferme.
        new ReflectionProperty(XpTransaction::class, 'amount')->setValue($transaction, 9999);

        $this->expectException(LedgerIsNotRewritable::class);

        $this->ledger->commit();
    }

    public function testATransactionCannotBeDeleted(): void
    {
        $transaction = $this->record(Uuid::v7(), Uuid::v7(), self::breakdownOf(90));

        $this->expectException(LedgerIsNotRewritable::class);

        $this->entityManager->remove($transaction);
        $this->entityManager->flush();
    }

    private function record(Uuid $userId, Uuid $sourceId, XpBreakdown $breakdown): XpTransaction
    {
        $transaction = XpTransaction::creditFor(
            $userId,
            $sourceId,
            Discipline::Running,
            2700,
            new XpAward($breakdown, 'v1-000000000000'),
            $this->now,
        );

        $this->ledger->add($transaction);
        $this->ledger->commit();

        return $transaction;
    }

    private static function breakdownOf(int $amount): XpBreakdown
    {
        return new XpBreakdown(new XpBreakdownLine(XpBreakdownSource::Base, $amount));
    }
}
