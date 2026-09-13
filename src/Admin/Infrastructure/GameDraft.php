<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure;

use Doctrine\DBAL\Connection;
use LogicException;

/**
 * Le verrou commun précède tout flush des tables éditables, publication comprise.
 * La révision envoyée par le formulaire protège aussi le temps passé hors transaction.
 * https://symfony.com/doc/current/doctrine.html#querying-with-sql.
 */
final readonly class GameDraft
{
    public function __construct(private Connection $connection)
    {
    }

    public function revision(): int
    {
        return self::integer($this->connection->fetchOne('SELECT draft_revision FROM game_ruleset WHERE id = 1'));
    }

    public function lock(int $expectedRevision): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new LogicException('Le brouillon doit être verrouillé dans une transaction.');
        }
        $revision = self::integer($this->connection->fetchOne('SELECT draft_revision FROM game_ruleset WHERE id = 1 FOR UPDATE'));
        if ($expectedRevision !== $revision) {
            throw new LogicException('Le brouillon a été modifié depuis votre ouverture de la page. Actualisez avant de réessayer.');
        }
    }

    public function advance(): void
    {
        $this->connection->executeStatement('UPDATE game_ruleset SET draft_revision = draft_revision + 1 WHERE id = 1');
    }

    private static function integer(mixed $value): int
    {
        if (!\is_int($value) && !(\is_string($value) && ctype_digit($value))) {
            throw new LogicException('La révision du brouillon est absente ou invalide.');
        }

        return (int) $value;
    }
}
