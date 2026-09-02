<?php

declare(strict_types=1);

namespace App\Tests\Performance;

use App\Rewards\Infrastructure\Doctrine\InventoryItemRepository;
use App\Shared\Application\GameRulesets;
use App\Shared\Infrastructure\Config\DatabaseGameRulesets;
use App\Tests\Support\ApiTestCase;
use Closure;
use DateTimeImmutable;
use Symfony\Bridge\Doctrine\DataCollector\DoctrineDataCollector;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\Uid\Uuid;

/**
 * Mesure reproductible de la part configuration des quatre routes sensibles de #260.
 *
 * Le temps absolu dépend de Docker/CI, donc le contrat testé est structurel : une lecture
 * froide lit le pointeur puis un snapshot publié ; les lectures chaudes suivantes ne lisent
 * que le pointeur scalaire. Les p50/p95 et le nombre total de requêtes SQL sont imprimés par
 * `make perf-ruleset`, afin que la PR compare les mêmes conditions avant/après sans figer
 * un chronomètre de machine dans la CI.
 */
#[\PHPUnit\Framework\Attributes\Group('performance')]
final class RulesetEndpointPerformanceTest extends ApiTestCase
{
    private const int DEFAULT_HOT_SAMPLES = 10;

    private const int WARMUP_SAMPLES = 10;

    public function testColdAndHotRulesetQueriesForEveryGameplayEndpoint(): void
    {
        $account = $this->openAccount('ruleset-performance@grrind.app');
        $this->client->disableReboot();
        $inventory = self::getContainer()->get(InventoryItemRepository::class);
        self::assertInstanceOf(InventoryItemRepository::class, $inventory);
        // Un inventaire vide ne lit légitimement aucun objet. Une ligne existante mesure le
        // vrai chemin chaud de résolution d'image, traduction et modificateurs.
        $inventory->grant($account->id, 'WORN_RUNNING_SHOES', Uuid::v7(), new DateTimeImmutable());

        /** @var array<string, Closure(int): Response> $endpoints */
        $endpoints = [
            'GET /api/enemies' => fn (int $sample): Response => $this->get('/api/enemies', $account->headers),
            'GET /api/shop' => fn (int $sample): Response => $this->get('/api/shop', $account->headers),
            'GET /api/inventory' => fn (int $sample): Response => $this->get('/api/inventory', $account->headers),
            'POST /api/battles' => fn (int $sample): Response => $this->post('/api/battles', [], [...$account->headers, 'Idempotency-Key' => 'perf-battle-'.$sample]),
        ];

        $report = [];
        foreach ($endpoints as $name => $request) {
            $this->emptyPublishedRulesetCache();
            $cold = $this->measure($request, 0);
            self::assertSame(2, $cold['rulesetQueries'], $name.' doit lire le pointeur et reconstruire un unique snapshot à froid.');

            // Un combat fait tourner PostgreSQL et le noyau Docker à froid. Les mesures de
            // comparaison passent donc par un warmup non compté avant l'échantillon robuste.
            for ($sample = 1; $sample <= self::WARMUP_SAMPLES; ++$sample) {
                $warmup = $this->measure($request, -$sample);
                self::assertSame(1, $warmup['rulesetQueries'], $name.' ne doit lire que le pointeur pendant le warmup.');
            }

            $samples = [];
            for ($sample = 1; $sample <= $this->hotSamples(); ++$sample) {
                $hot = $this->measure($request, $sample);
                self::assertSame(1, $hot['rulesetQueries'], $name.' ne doit lire que le pointeur sans réhydrater la configuration à chaud.');
                $samples[] = $hot;
            }

            $milliseconds = array_map(static fn (array $measurement): float => $measurement['milliseconds'], $samples);
            sort($milliseconds);
            $totalQueries = array_sum(array_map(static fn (array $measurement): int => $measurement['sqlQueries'], $samples));
            $report[$name] = [
                'cold' => $cold,
                'hot' => [
                    'samples' => $this->hotSamples(),
                    'medianMs' => self::percentile($milliseconds, 50),
                    'p95Ms' => self::percentile($milliseconds, 95),
                    'sqlQueries' => $totalQueries,
                    'rulesetQueries' => array_sum(array_map(static fn (array $measurement): int => $measurement['rulesetQueries'], $samples)),
                ],
            ];
        }

        fwrite(\STDERR, "\n#260 ruleset endpoint measurements\n".json_encode($report, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR)."\n");
    }

    /**
     * @param Closure(int): Response $request
     *
     * @return array{milliseconds: float, sqlQueries: int, rulesetQueries: int}
     */
    private function measure(Closure $request, int $sample): array
    {
        $this->client->enableProfiler();
        $startedAt = hrtime(true);
        $response = $request($sample);
        $elapsedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;
        self::assertContains($response->getStatusCode(), [Response::HTTP_OK, Response::HTTP_CREATED], (string) $response->getContent());

        $profile = $this->client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        $sqlQueries = 0;
        $rulesetQueries = 0;
        foreach ($collector->getQueries() as $queries) {
            self::assertIsArray($queries);
            foreach ($queries as $query) {
                self::assertIsArray($query);
                $sql = $query['sql'] ?? '';
                self::assertIsString($sql);
                ++$sqlQueries;
                if (1 === preg_match('/\\bgame_ruleset\\b/i', $sql)) {
                    ++$rulesetQueries;
                }
            }
        }

        return ['milliseconds' => round($elapsedMilliseconds, 3), 'sqlQueries' => $sqlQueries, 'rulesetQueries' => $rulesetQueries];
    }

    private function emptyPublishedRulesetCache(): void
    {
        $cache = self::getContainer()->get('game.ruleset');
        self::assertInstanceOf(TagAwareAdapterInterface::class, $cache);
        $cache->clear();

        $rulesets = self::getContainer()->get(GameRulesets::class);
        self::assertInstanceOf(DatabaseGameRulesets::class, $rulesets);
        $rulesets->reset();
    }

    private function hotSamples(): int
    {
        $configured = getenv('GRRIND_PERF_HOT_SAMPLES');
        if (false === $configured || '' === $configured) {
            return self::DEFAULT_HOT_SAMPLES;
        }

        $samples = filter_var($configured, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 250]]);
        self::assertIsInt($samples, 'GRRIND_PERF_HOT_SAMPLES doit être compris entre 1 et 250.');

        return $samples;
    }

    /** @param list<float> $sortedMilliseconds */
    private static function percentile(array $sortedMilliseconds, int $percentile): float
    {
        self::assertNotEmpty($sortedMilliseconds);
        $index = (int) ceil((\count($sortedMilliseconds) * $percentile) / 100) - 1;

        return $sortedMilliseconds[$index];
    }
}
