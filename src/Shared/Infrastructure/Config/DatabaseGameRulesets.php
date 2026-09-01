<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Config;

use App\Shared\Application\GameRulesets;
use Doctrine\DBAL\Connection;
use LogicException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

/**
 * Une requête lit la révision puis le snapshot immuable correspondant. La mémoire locale est
 * réinitialisée entre requêtes FrankenPHP ; un process long ne mélange donc jamais deux versions.
 * Le cache est une accélération : toute erreur de cache retombe immédiatement sur PostgreSQL.
 */
final class DatabaseGameRulesets implements GameRulesets, ResetInterface
{
    /** @var array{revision: int, version: string, snapshot: array<string, mixed>}|null */
    private ?array $current = null;

    public function __construct(private readonly Connection $connection, private readonly CacheInterface $cache, private readonly string $yamlGameplayVersion)
    {
    }

    public function snapshot(): array
    {
        return $this->current()['snapshot'];
    }

    public function version(): string
    {
        return $this->current()['version'];
    }

    public function revision(): int
    {
        return $this->current()['revision'];
    }

    public function reset(): void
    {
        $this->current = null;
    }

    /** @return array{revision: int, version: string, snapshot: array<string, mixed>} */
    private function current(): array
    {
        if (null !== $this->current) {
            return $this->current;
        }

        /** @var array{revision: int|string, version: string}|false $revision */
        $revision = $this->connection->fetchAssociative('SELECT revision, version FROM game_ruleset WHERE id = 1');
        if (false === $revision) {
            throw new LogicException('Le snapshot de jeu est absent. Appliquer les migrations.');
        }
        $expectedRevision = (int) $revision['revision'];
        // Le YAML restant peut changer au déploiement sans révision DB : il appartient à
        // la clé de cache afin qu'un ancien snapshot sérialisé ne conserve pas son hash.
        $key = 'game.ruleset.'.$expectedRevision.'.'.$this->yamlGameplayVersion;
        try {
            /** @var array{revision: int, version: string, snapshot: array<string, mixed>} $current */
            $current = $this->cache->get($key, function (ItemInterface $item) use ($expectedRevision): array {
                $item->tag('game.ruleset');

                return $this->load($expectedRevision);
            });
        } catch (Throwable) {
            $current = $this->load($expectedRevision);
        }

        return $this->current = $current;
    }

    /** @return array{revision: int, version: string, snapshot: array<string, mixed>} */
    private function load(int $expectedRevision): array
    {
        /** @var array{revision: int|string, version: string, snapshot: string|array<string, mixed>}|false $row */
        $row = $this->connection->fetchAssociative('SELECT revision, version, snapshot FROM game_ruleset WHERE id = 1');
        if (false === $row) {
            throw new LogicException('Le snapshot de jeu est absent. Appliquer les migrations.');
        }
        $revision = (int) $row['revision'];
        if ($expectedRevision !== $revision) {
            // Une publication a gagné entre les deux lectures : repartir sur sa révision,
            // jamais assembler des tables du moment avec le snapshot précédent.
            return $this->load($revision);
        }
        $snapshot = \is_array($row['snapshot']) ? $row['snapshot'] : json_decode($row['snapshot'], true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($snapshot));
        /** @var array<string, mixed> $snapshot */

        return ['revision' => $revision, 'version' => GameRulesetVersion::of($this->yamlGameplayVersion, $snapshot), 'snapshot' => $snapshot];
    }
}
