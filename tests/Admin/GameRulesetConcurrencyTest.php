<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Deux consoles EasyAdmin peuvent publier simultanément. Le verrou pessimiste du publisher
 * porte sur cette ligne unique : PostgreSQL doit donc imposer l'ordre, pas seulement Doctrine.
 *
 * Symfony documente l'accès DBAL bas niveau pour les requêtes SQL ciblées :
 * https://symfony.com/doc/current/doctrine.html#querying-with-sql
 */
final class GameRulesetConcurrencyTest extends KernelTestCase
{
    public function testTwoPublishingConnectionsSerializeOnTheMonotonicRulesetRevision(): void
    {
        $first = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $first);
        /** @var array{driver: 'pdo_pgsql', host: string, port?: int, user: string, password: string, dbname: string, serverVersion?: string} $connectionParameters */
        $connectionParameters = $first->getParams();
        $second = DriverManager::getConnection($connectionParameters);

        try {
            $initialRevision = self::revisionOf($first->fetchOne('SELECT revision FROM game_ruleset WHERE id = 1'));

            $first->beginTransaction();
            self::assertSame(1, $first->executeQuery('SELECT id FROM game_ruleset WHERE id = 1 FOR UPDATE')->rowCount());

            // Une autre publication ne peut ni lire la révision à publier ni l'écraser :
            // lock_timeout borne le test sans transformer l'attente normale de production.
            $second->executeStatement("SET lock_timeout = '50ms'");
            try {
                $second->executeQuery('SELECT id FROM game_ruleset WHERE id = 1 FOR UPDATE');
                self::fail('La seconde publication a obtenu le verrou avant le commit de la première.');
            } catch (Exception $exception) {
                self::assertStringContainsString('lock timeout', strtolower($exception->getMessage()));
            }

            self::assertSame($initialRevision + 1, self::revisionOf($first->fetchOne('UPDATE game_ruleset SET revision = revision + 1 WHERE id = 1 RETURNING revision')));
            $first->commit();

            // Une fois la première publication terminée, la seconde observe sa révision et
            // publie la suivante : aucune mise à jour perdue, aucune révision intermédiaire.
            $second->beginTransaction();
            self::assertSame($initialRevision + 1, self::revisionOf($second->fetchOne('SELECT revision FROM game_ruleset WHERE id = 1 FOR UPDATE')));
            self::assertSame($initialRevision + 2, self::revisionOf($second->fetchOne('UPDATE game_ruleset SET revision = revision + 1 WHERE id = 1 RETURNING revision')));
            $second->commit();

            self::assertSame($initialRevision + 2, self::revisionOf($first->fetchOne('SELECT revision FROM game_ruleset WHERE id = 1')));
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

    private static function revisionOf(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        self::fail('La révision PostgreSQL doit être un entier non négatif.');
    }
}
