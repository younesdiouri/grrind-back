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
 * Chaque opération lit le pointeur léger {révision, empreinte}, puis le snapshot immuable qui
 * porte cette révision seulement sur cache miss. La clé de contenu évite qu'un reset de base,
 * qui remet la révision à 1, ne serve le snapshot d'une base précédente.
 */
final class DatabaseGameRulesets implements GameRulesets, ResetInterface
{
    /** @var array{revision: int, version: string, snapshot: array<string, mixed>}|null */
    private ?array $current = null;

    public function __construct(private readonly Connection $connection, private readonly CacheInterface $cache)
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
        // porte la version de contenu, ce qui interdit qu'un cache local d'une base reset
        // serve un snapshot différent dont la révision est revenue à 1.
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $pointer = $this->pointer();
            try {
                /** @var array{revision: int, version: string, snapshot: array<string, mixed>} $current */
                $current = $this->cache->get('game.ruleset.'.$pointer['version'], function (ItemInterface $item) use ($pointer): array {
                    $item->tag('game.ruleset');

                    return $this->load($pointer);
                });
                if ($current['revision'] !== $pointer['revision']) {
                    $key = 'game.ruleset.'.$pointer['version'];
                    if (!$this->cache->delete($key)) {
                        $current = $this->load($pointer);
                    } else {
                        $current = $this->cache->get($key, function (ItemInterface $item) use ($pointer): array {
                            $item->tag('game.ruleset');

                            return $this->load($pointer);
                        });
                    }
                }
                /** @var array{revision: int, version: string, snapshot: array<string, mixed>} $current */
            } catch (Throwable $exception) {
                if ($exception instanceof PublishedRulesetMoved) {
                    // Le cache peut appeler son callback avant de relayer l'exception : ne
                    // pas recharger N dans le catch, relire directement le pointeur N+1.
                    continue;
                }
                try {
                    $current = $this->load($pointer);
                } catch (PublishedRulesetMoved) {
                    continue;
                }
            }
            if ($current['version'] === $pointer['version'] && $current['version'] === GameRulesetVersion::of($current['snapshot'])) {
                return $this->current = $current;
            }
        }

        throw new LogicException('La révision de jeu a changé pendant son chargement. Réessayer l’opération.');
    }

    /** @return array{revision: int, version: string} */
    private function pointer(): array
    {
        /** @var array{revision: int|string, version: string}|false $pointer */
        $pointer = $this->connection->fetchAssociative('SELECT revision, version FROM game_ruleset WHERE id = 1');
        if (false === $pointer) {
            throw new LogicException('Le snapshot de jeu est absent. Appliquer les migrations.');
        }

        if ((!\is_int($pointer['revision']) && !\is_string($pointer['revision'])) || '' === $pointer['version']) {
            throw new LogicException('La révision de jeu est invalide.');
        }

        return ['revision' => (int) $pointer['revision'], 'version' => $pointer['version']];
    }

    /**
     * @param array{revision: int, version: string} $expectedPointer
     *
     * @return array{revision: int, version: string, snapshot: array<string, mixed>}
     */
    private function load(array $expectedPointer): array
    {
        /** @var array{revision: int|string, version: string, snapshot: string|array<string, mixed>}|false $row */
        $row = $this->connection->fetchAssociative('SELECT revision, version, snapshot FROM game_ruleset WHERE id = 1 AND revision = :revision AND version = :version', ['revision' => $expectedPointer['revision'], 'version' => $expectedPointer['version']]);
        if (false === $row) {
            throw new PublishedRulesetMoved('La révision de jeu a changé pendant son chargement.');
        }
        $revision = (int) $row['revision'];
        $snapshot = \is_array($row['snapshot']) ? $row['snapshot'] : json_decode($row['snapshot'], true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($snapshot));
        /** @var array<string, mixed> $snapshot */
        $version = GameRulesetVersion::of($snapshot);
        if ($version !== $row['version']) {
            throw new LogicException('L’empreinte du snapshot de jeu publié est invalide. Rejouer la publication.');
        }

        return ['revision' => $revision, 'version' => $version, 'snapshot' => $snapshot];
    }
}
