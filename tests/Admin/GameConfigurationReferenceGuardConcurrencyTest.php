<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Domain\GameItem;
use App\Admin\Infrastructure\GameConfigurationReferenceGuard;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** La décision delete/activation est sérialisée par PostgreSQL, pas par l'objet Doctrine périmé. */
final class GameConfigurationReferenceGuardConcurrencyTest extends KernelTestCase
{
    public function testDeleteLockMakesAConcurrentFirstActivationFailInsteadOfPublishingAGhost(): void
    {
        [$item, $first] = $this->draftItem();
        /** @var array{driver: 'pdo_pgsql', host: string, port?: int, user: string, password: string, dbname: string, serverVersion?: string} $parameters */
        $parameters = $first->getParams();
        $second = DriverManager::getConnection($parameters);

        try {
            $first->beginTransaction();
            new GameConfigurationReferenceGuard($first)->assertDeletable($item);

            $second->executeStatement("SET lock_timeout = '50ms'");
            try {
                $second->executeStatement('UPDATE game_item SET active = TRUE, ever_published_active = TRUE WHERE id = ?', [$item->getId()->toRfc4122()]);
                self::fail('Une activation ne peut pas passer pendant que la suppression tient la ligne.');
            } catch (Exception $exception) {
                self::assertStringContainsString('lock timeout', strtolower($exception->getMessage()));
            }

            self::assertSame(1, $first->executeStatement('DELETE FROM game_item WHERE id = ?', [$item->getId()->toRfc4122()]));
            $first->commit();

            // Le flush différé de l'autre requête doit constater zéro ligne, jamais publier
            // le snapshot mémorisé d'une entité qui vient d'être retirée.
            self::assertSame(0, $second->executeStatement('UPDATE game_item SET active = TRUE, ever_published_active = TRUE WHERE id = ?', [$item->getId()->toRfc4122()]));
        } finally {
            if ($first->isTransactionActive()) {
                $first->rollBack();
            }
            if ($second->isTransactionActive()) {
                $second->rollBack();
            }
            $second->close();
        }
    }

    public function testDeleteThatWaitsForAFirstActivationRechecksThePublishedMarker(): void
    {
        [$item, $first] = $this->draftItem();
        /** @var array{driver: 'pdo_pgsql', host: string, port?: int, user: string, password: string, dbname: string, serverVersion?: string} $parameters */
        $parameters = $first->getParams();
        $second = DriverManager::getConnection($parameters);

        try {
            $second->beginTransaction();
            // Une paire peut être repassée inactive après sa première publication : c'est le
            // marqueur durable, et non l'état courant, qui doit conserver sa clé.
            self::assertSame(1, $second->executeStatement('UPDATE game_item SET active = FALSE, ever_published_active = TRUE WHERE id = ?', [$item->getId()->toRfc4122()]));

            $first->beginTransaction();
            $first->executeStatement("SET LOCAL lock_timeout = '50ms'");
            try {
                new GameConfigurationReferenceGuard($first)->assertDeletable($item);
                self::fail('Le DELETE ne peut pas décider depuis l’objet hydraté avant l’activation.');
            } catch (Exception $exception) {
                self::assertStringContainsString('lock timeout', strtolower($exception->getMessage()));
            }
            $first->rollBack();
            $second->commit();

            $first->beginTransaction();
            try {
                new GameConfigurationReferenceGuard($first)->assertDeletable($item);
                self::fail('La garde doit relire ever_published_active après la publication concurrente.');
            } catch (LogicException $exception) {
                self::assertStringContainsString('déjà été publiée active', $exception->getMessage());
            }
            $first->rollBack();
        } finally {
            if ($first->isTransactionActive()) {
                $first->rollBack();
            }
            if ($second->isTransactionActive()) {
                $second->rollBack();
            }
            $second->close();
            $first->executeStatement('DELETE FROM game_item WHERE id = ?', [$item->getId()->toRfc4122()]);
        }
    }

    /** @return array{GameItem, Connection} */
    private function draftItem(): array
    {
        $manager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $item = new GameItem();
        $item->setKey('DELETE_RACE_'.bin2hex(random_bytes(6)));
        $item->setActive(false);
        $item->setSortOrder(random_int(8_000_000, 9_000_000));
        $manager->persist($item);
        $manager->flush();

        return [$item, $connection];
    }
}
