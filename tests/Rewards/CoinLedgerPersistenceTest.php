<?php

declare(strict_types=1);

namespace App\Tests\Rewards;

use App\Rewards\Application\CoinLedger;
use App\Rewards\Domain\CoinReason;
use App\Rewards\Domain\CoinTransaction;
use App\Rewards\Domain\Exception\InsufficientCoinBalance;
use App\Rewards\Infrastructure\Doctrine\CoinTransactionRepository;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Le ledger de pièces contre une vraie base — même geste que
 * {@see LootRollPersistenceTest} : ce qu'aucun test en mémoire ne
 * prouve, c'est que le mapping Doctrine et la migration `rewards_coin_transaction` (#225)
 * s'accordent, que le solde se lit bien comme une somme, et que le garde-fou du solde
 * négatif tient *sous verrou*, à l'écriture, dans la vraie base — pas seulement dans une
 * assertion en mémoire.
 */
final class CoinLedgerPersistenceTest extends ApiTestCase
{
    public function testACreditedTransactionRoundTripsThroughTheDatabase(): void
    {
        $entityManager = self::entityManager();
        $repository = self::repository();

        $userId = Uuid::v7();
        $sourceId = Uuid::v7();
        $occurredAt = new DateTimeImmutable('2026-08-30T12:00:00+00:00');

        $transaction = $repository->record($userId, CoinReason::WorkoutDrop, $sourceId, 12, $occurredAt);
        $entityManager->clear();

        $reloaded = $repository->find($transaction->id());

        self::assertInstanceOf(CoinTransaction::class, $reloaded);
        self::assertTrue($userId->equals($reloaded->userId()));
        self::assertTrue($sourceId->equals($reloaded->sourceId()));
        self::assertSame(CoinReason::WorkoutDrop, $reloaded->reason());
        self::assertSame(12, $reloaded->amount());
        self::assertEquals($occurredAt, $reloaded->occurredAt());
    }

    /**
     * Le solde est une somme, jamais une colonne : plusieurs écritures d'un même joueur, de
     * raisons différentes, se retrouvent additionnées — et n'affectent en rien le solde
     * d'un autre joueur.
     */
    public function testTheBalanceIsTheSumOfEveryTransaction(): void
    {
        $repository = self::repository();

        $userId = Uuid::v7();
        $otherPlayerId = Uuid::v7();
        $now = new DateTimeImmutable('2026-08-30T12:00:00+00:00');

        $repository->record($userId, CoinReason::WorkoutDrop, Uuid::v7(), 10, $now);
        $repository->record($userId, CoinReason::BattleDrop, Uuid::v7(), 7, $now);
        $repository->record($otherPlayerId, CoinReason::WorkoutDrop, Uuid::v7(), 999, $now);

        self::assertSame(17, $repository->balanceOf($userId));
        self::assertSame(999, $repository->balanceOf($otherPlayerId));
        self::assertSame(0, $repository->balanceOf(Uuid::v7()), 'Un joueur qui n\'a jamais rien reçu a un solde nul, sans ligne à lire.');
    }

    /**
     * **Le cœur du ticket.** Aucune ligne négative n'existe encore en production — la
     * première viendra de la boutique au #229 — donc c'est ce test qui construit lui-même
     * le cas : un joueur avec un petit solde, et une écriture qui le ferait passer sous
     * zéro. La garde doit refuser *avant* d'écrire, sous le verrou, dans la même
     * transaction que la tentative.
     */
    public function testAWriteThatWouldCrossZeroIsRefused(): void
    {
        $repository = self::repository();

        $userId = Uuid::v7();
        $now = new DateTimeImmutable('2026-08-30T12:00:00+00:00');

        $repository->record($userId, CoinReason::WorkoutDrop, Uuid::v7(), 10, $now);

        $this->expectException(InsufficientCoinBalance::class);

        try {
            // Un montant que rien ne produit encore en pratique — voir le docblock de la
            // classe : le garde-fou s'applique au signe, pas à la raison qui le porte.
            $repository->record($userId, CoinReason::WorkoutDrop, Uuid::v7(), -11, $now);
        } finally {
            // Refusée ou pas, la tentative n'a rien laissé derrière elle : le solde reste
            // exactement celui d'avant, une seule ligne au ledger.
            self::assertSame(10, $repository->balanceOf($userId));
        }
    }

    /**
     * **La datation par le fait, pas par l'insertion.** Trois écritures, insérées dans
     * l'ordre inverse de la date qu'elles portent — exactement le cas d'un import qui
     * remonte plusieurs jours d'un coup. `occurredAt` doit rester celle du fait, jamais
     * celle, forcément croissante, à laquelle l'UUID v7 a été généré.
     */
    public function testOccurredAtIsTheDateOfTheFactNotOfTheInsertion(): void
    {
        $entityManager = self::entityManager();
        $repository = self::repository();

        $userId = Uuid::v7();

        $threeDaysAgo = new DateTimeImmutable('2026-08-27T08:00:00+00:00');
        $twoDaysAgo = new DateTimeImmutable('2026-08-28T08:00:00+00:00');
        $yesterday = new DateTimeImmutable('2026-08-29T08:00:00+00:00');

        // Insérées du plus récent fait au plus ancien : l'inverse de leur `occurredAt`.
        $mostRecentFact = $repository->record($userId, CoinReason::WorkoutDrop, Uuid::v7(), 5, $yesterday);
        $middleFact = $repository->record($userId, CoinReason::WorkoutDrop, Uuid::v7(), 5, $twoDaysAgo);
        $oldestFact = $repository->record($userId, CoinReason::WorkoutDrop, Uuid::v7(), 5, $threeDaysAgo);

        $entityManager->clear();

        self::assertEquals($yesterday, self::reload($repository, $mostRecentFact)->occurredAt());
        self::assertEquals($twoDaysAgo, self::reload($repository, $middleFact)->occurredAt());
        self::assertEquals($threeDaysAgo, self::reload($repository, $oldestFact)->occurredAt());

        // L'instant de l'écriture n'est pas perdu pour autant : les identifiants restent
        // triables dans l'ordre où les lignes ont été insérées, pas dans celui des faits
        // qu'elles rapportent.
        self::assertTrue($mostRecentFact->id()->toRfc4122() < $middleFact->id()->toRfc4122());
        self::assertTrue($middleFact->id()->toRfc4122() < $oldestFact->id()->toRfc4122());
    }

    /**
     * `CoinLedger` n'a encore aucun consommateur en production (#226, #227) — voir son
     * docblock — donc rien d'autre dans le conteneur ne le référence, et le compilateur
     * retirerait un service qu'on irait chercher par son id. Construite directement plutôt
     * que résolue : elle n'a qu'une dépendance, déjà en main.
     */
    public function testCoinLedgerCreditDelegatesToTheGuardedWrite(): void
    {
        $ledger = new CoinLedger(self::repository());

        $userId = Uuid::v7();
        $now = new DateTimeImmutable('2026-08-30T12:00:00+00:00');

        $ledger->credit($userId, CoinReason::BattleDrop, Uuid::v7(), 42, $now);

        self::assertSame(42, $ledger->balanceOf($userId));
    }

    private static function reload(CoinTransactionRepository $repository, CoinTransaction $transaction): CoinTransaction
    {
        $reloaded = $repository->find($transaction->id());
        self::assertInstanceOf(CoinTransaction::class, $reloaded);

        return $reloaded;
    }

    private static function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private static function repository(): CoinTransactionRepository
    {
        $repository = self::getContainer()->get(CoinTransactionRepository::class);
        self::assertInstanceOf(CoinTransactionRepository::class, $repository);

        return $repository;
    }
}
