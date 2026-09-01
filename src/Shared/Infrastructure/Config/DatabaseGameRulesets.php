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
 * Chaque opération lit d'abord la révision scalaire, puis le snapshot immuable portant cette
 * révision. Une clé cache révisionnée garde donc deux machines Fly cohérentes même si leurs
 * pools filesystem ne se parlent pas ou si l'invalidation post-commit échoue. Le cache évite
 * seulement l'hydratation du graphe ; PostgreSQL reste la source de vérité du pointeur courant.
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

        // Le SELECT scalaire est volontairement le seul coût de cohérence à chaud. La clé
        // porte ensuite la révision, ce qui interdit qu'un cache local périmé serve N après
        // une publication N+1 sur une autre machine.
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $revision = $this->revisionPointer();
            $key = 'game.ruleset.'.$this->yamlGameplayVersion.'.'.$revision;
            try {
                /** @var array{revision: int, version: string, snapshot: array<string, mixed>} $current */
                $current = $this->cache->get($key, function (ItemInterface $item) use ($revision): array {
                    $item->tag('game.ruleset');

                    return $this->load($revision);
                });
            } catch (Throwable) {
                $current = $this->load($revision);
            }
            if ($current['revision'] === $revision) {
                return $this->current = $current;
            }
        }

        throw new LogicException('La révision de jeu a changé pendant son chargement. Réessayer l’opération.');
    }

    private function revisionPointer(): int
    {
        $revision = $this->connection->fetchOne('SELECT revision FROM game_ruleset WHERE id = 1');
        if (false === $revision) {
            throw new LogicException('Le snapshot de jeu est absent. Appliquer les migrations.');
        }

        if (!\is_int($revision) && !\is_string($revision) && !\is_float($revision)) {
            throw new LogicException('La révision de jeu est invalide.');
        }

        return (int) $revision;
    }

    /** @return array{revision: int, version: string, snapshot: array<string, mixed>} */
    private function load(int $expectedRevision): array
    {
        /** @var array{revision: int|string, version: string, snapshot: string|array<string, mixed>}|false $row */
        $row = $this->connection->fetchAssociative('SELECT revision, version, snapshot FROM game_ruleset WHERE id = 1 AND revision = :revision', ['revision' => $expectedRevision]);
        if (false === $row) {
            throw new LogicException('La révision de jeu a changé pendant son chargement.');
        }
        $revision = (int) $row['revision'];
        $snapshot = \is_array($row['snapshot']) ? $row['snapshot'] : json_decode($row['snapshot'], true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($snapshot));
        /** @var array<string, mixed> $snapshot */

        return ['revision' => $revision, 'version' => GameRulesetVersion::of($this->yamlGameplayVersion, $snapshot), 'snapshot' => $snapshot];
    }
}
