<?php

declare(strict_types=1);

namespace App\Tests\Rewards;

use App\Rewards\Domain\LootRoll;
use App\Rewards\Domain\LootRollOrigin;
use App\Rewards\Domain\LootRollOutcome;
use App\Rewards\Infrastructure\Doctrine\LootRollRepository;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * La ligne d'audit contre une vraie base : ce qu'aucun test en mémoire ne prouve, c'est que
 * le mapping Doctrine et la migration `rewards_loot_roll` (#28) s'accordent — les deux
 * colonnes `JSONB`, la longueur de la graine, l'enum d'origine. Personne n'appelle encore
 * ce dépôt en production (#226, #227) ; ce test est la seule preuve que l'écrire fonctionnera
 * le jour venu.
 */
final class LootRollPersistenceTest extends ApiTestCase
{
    public function testARecordedRollRoundTripsThroughTheDatabase(): void
    {
        $entityManager = self::entityManager();
        $repository = self::repository();

        $userId = Uuid::v7();
        $causeId = Uuid::v7();

        $roll = LootRoll::record(
            $userId,
            LootRollOrigin::Workout,
            $causeId,
            random_bytes(32),
            new LootRollOutcome(
                tableKey: 'STARTER_SESSION_DROP',
                tableVersion: 1,
                effectiveLootLuckPercent: 20,
                itemRoll: 7,
                itemTotalWeight: 100,
                items: ['WORN_RUNNING_SHOES'],
                coins: 12,
            ),
            new DateTimeImmutable('2026-08-30T12:00:00+00:00'),
        );

        $repository->add($roll);
        $repository->commit();
        $entityManager->clear();

        $reloaded = $repository->ofId($roll->id());

        self::assertNotNull($reloaded);
        self::assertTrue($userId->equals($reloaded->userId()));
        self::assertTrue($causeId->equals($reloaded->causeId()));
        self::assertSame(LootRollOrigin::Workout, $reloaded->origin());
        self::assertSame($roll->seed(), $reloaded->seed());
        self::assertSame('STARTER_SESSION_DROP', $reloaded->tableKey());
        self::assertSame(1, $reloaded->tableVersion());
        self::assertSame(20, $reloaded->effectiveLootLuckPercent());
        // `assertEquals`, pas `assertSame` : PostgreSQL réordonne lui-même les clés d'un
        // objet JSONB au stockage (par longueur puis ordre alphabétique) — voir le docblock
        // de `App\Combat\Domain\Battle` pour la même remarque sur `timeline`. L'ordre des
        // clés n'est pas signifiant ici, contrairement à l'ordre des éléments d'une liste.
        self::assertEquals(['itemRoll' => 7, 'itemTotalWeight' => 100], $reloaded->roll());
        self::assertEquals(['items' => ['WORN_RUNNING_SHOES'], 'coins' => 12], $reloaded->result());
        self::assertEquals($roll->rolledAt(), $reloaded->rolledAt());
    }

    private static function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private static function repository(): LootRollRepository
    {
        $repository = self::getContainer()->get(LootRollRepository::class);
        self::assertInstanceOf(LootRollRepository::class, $repository);

        return $repository;
    }
}
