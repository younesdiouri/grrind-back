<?php

declare(strict_types=1);

namespace App\Tests\Community;

use App\Community\Application\AlamResolution;
use App\Community\Domain\AlamMode;
use App\Community\Domain\AlamRun;
use App\Shared\Application\AlamRewards;
use App\Tests\Community\Domain\AlamOutcomeTest;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

final class AlamResolutionTest extends TestCase
{
    public function testSeedReproducesFactsAndLegendaryRequiresFinalVictory(): void
    {
        $rules = AlamOutcomeTest::rules()->values;
        $rules['equipment_chance_permille'] = 1000;
        $rules['legendary_chance_permille'] = 1000;
        $snapshot = ['alam' => $rules, 'items' => [['key' => 'NAFS_ESSENCE', 'kind' => 'RESOURCE', 'rarity' => 'COMMON'], ['key' => 'BOOTS', 'kind' => 'EQUIPMENT', 'rarity' => 'COMMON'], ['key' => 'CROWN', 'kind' => 'EQUIPMENT', 'rarity' => 'LEGENDARY']]];
        $now = new DateTimeImmutable('2026-09-14 10:00:00 UTC');
        $run = new AlamRun(Uuid::v7(), AlamMode::Manual, $now->modify('-1 day'), $now, $now, 1, 'test', $snapshot);
        $rewards = $this->createStub(AlamRewards::class);
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('https://example.test/placeholder.png');
        $resolution = new AlamResolution($rewards, $urls);
        $targets = AlamOutcomeTest::rules()->targets(1);
        $participants = [['playerId' => Uuid::v7()->toRfc4122(), 'displayName' => 'A', 'avatarUrl' => null, 'contribution' => 400, 'gauges' => AlamResolution::gauges($targets, $targets)]];
        $result = $resolution->resolve($run, $participants, $targets, $now);
        self::assertSame($result, $resolution->resolve($run, $participants, $targets, $now));
        self::assertIsArray($result['encounters']);
        foreach ($result['encounters'] as $index => $encounter) {
            self::assertIsArray($encounter);
            self::assertTrue($encounter['won']);
            self::assertSame(1000000, $encounter['probabilityMillionths']);
            self::assertIsArray($encounter['drops']);
            self::assertCount(2 === $index ? 3 : 2, $encounter['drops']);
            self::assertIsArray($encounter['drops'][0]);
            self::assertSame(5, $encounter['drops'][0]['quantity']);
            if (2 === $index) {
                self::assertIsArray($encounter['drops'][2]);
                self::assertSame('LEGENDARY', $encounter['drops'][2]['rarity']);
            }
        }
        $defeat = $resolution->resolve($run, $participants, [], $now);
        self::assertIsArray($defeat['encounters']);
        foreach ($defeat['encounters'] as $encounter) {
            self::assertIsArray($encounter);
            self::assertFalse($encounter['won']);
            self::assertIsArray($encounter['drops']);
            self::assertCount(1, $encounter['drops']);
            self::assertIsArray($encounter['drops'][0]);
            self::assertSame('RESOURCE', $encounter['drops'][0]['kind']);
        }
    }

    /** Le boss est illustré depuis le catalogue figé de l'édition, jamais depuis le courant (#289). */
    public function testEncountersFreezeTheBossPosesOfTheRunSnapshot(): void
    {
        $rules = AlamOutcomeTest::rules()->values;
        \assert(\is_string($rules['enemy_key']));
        $now = new DateTimeImmutable('2026-09-14 10:00:00 UTC');
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route, array $parameters): string => 'https://example.test/'.(\is_string($parameters['name'] ?? null) ? $parameters['name'] : ''));
        $resolution = new AlamResolution($this->createStub(AlamRewards::class), $urls);
        $boss = ['key' => $rules['enemy_key'], 'image_paths' => ['idle' => 'a.png', 'attack' => 'b.png', 'hit' => 'c.png']];

        foreach ([[$boss], [['image_paths' => null] + $boss], []] as $position => $bosses) {
            $run = new AlamRun(Uuid::v7(), AlamMode::Manual, $now->modify('-1 day'), $now, $now, 1, 'test', ['alam' => $rules, 'items' => [], 'combat' => ['bosses' => $bosses]]);
            $result = $resolution->resolve($run, [], [], $now);
            self::assertIsArray($result['encounters']);
            self::assertIsArray($result['encounters'][0]);
            self::assertSame(0 === $position ? ['idle' => 'https://example.test/a.png', 'attack' => 'https://example.test/b.png', 'hit' => 'https://example.test/c.png'] : null, $result['encounters'][0]['imageUrls']);
        }
    }
}
