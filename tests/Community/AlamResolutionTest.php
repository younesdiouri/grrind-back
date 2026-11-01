<?php

declare(strict_types=1);

namespace App\Tests\Community;

use App\Community\Application\AlamResolution;
use App\Community\Domain\AlamMode;
use App\Community\Domain\AlamRun;
use App\Shared\Application\AlamRewards;
use App\Shared\Domain\Alam\AlamDifficulty;
use App\Shared\Domain\Alam\AlamRules;
use App\Tests\Community\Domain\AlamOutcomeTest;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

final class AlamResolutionTest extends TestCase
{
    public function testDifficultyUsesItsOwnLootAndThePreviewMatchesTheResolvedProbability(): void
    {
        $values = AlamOutcomeTest::rules()->values;
        $loot = [];
        foreach (AlamDifficulty::cases() as $difficulty) {
            $loot[$difficulty->value] = ['resource_base' => $difficulty->position() + 1, 'resource_progression' => 0, 'equipment_chance_permille' => 0, 'legendary_chance_permille' => 0];
        }
        $values['difficulty_loot'] = $loot;
        $rules = new AlamRules($values);
        $totals = $rules->targets(1, 250);
        $options = AlamResolution::options($totals, $rules, 1);
        self::assertSame(1000000, $options[0]['probabilityMillionths']);
        self::assertGreaterThanOrEqual($options[1]['probabilityMillionths'], $options[0]['probabilityMillionths']);
        self::assertGreaterThanOrEqual($options[2]['probabilityMillionths'], $options[1]['probabilityMillionths']);
        $now = new DateTimeImmutable('2026-10-26 12:00:00 UTC');
        $player = Uuid::v7()->toRfc4122();
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('https://example.test/essence.png');
        $rewards = $this->createMock(AlamRewards::class);
        $rewards->expects(self::exactly(3))->method('grant')->with(self::anything(), 1, self::anything(), self::anything(), self::anything(), self::anything());
        $resolution = new AlamResolution($rewards, $urls);
        foreach (AlamDifficulty::cases() as $difficulty) {
            $run = new AlamRun(Uuid::v7(), AlamMode::Manual, $now->modify('-1 day'), $now, $now, 1, 'test', ['alam' => $values, 'items' => [['key' => 'NAFS_ESSENCE', 'kind' => 'RESOURCE', 'rarity' => 'COMMON']]]);
            $run->difficulty = $difficulty;
            $result = $resolution->resolve($run, [['playerId' => $player, 'displayName' => 'A', 'avatarUrl' => null, 'contribution' => 100, 'gauges' => []]], $totals, $now);
            /** @var list<array{probabilityMillionths: int, drops: list<array{quantity: int}>}> $encounters */
            $encounters = $result['encounters'];
            self::assertCount(1, $encounters);
            self::assertSame($options[$difficulty->position()]['probabilityMillionths'], $encounters[0]['probabilityMillionths']);
            self::assertSame($difficulty->position() + 1, $encounters[0]['drops'][0]['quantity']);
        }
    }

    public function testSeedReproducesASingleEncounterAndLegendaryRequiresVictory(): void
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
        self::assertCount(1, $result['encounters']);
        foreach ($result['encounters'] as $index => $encounter) {
            self::assertIsArray($encounter);
            self::assertTrue($encounter['won']);
            self::assertSame(1000000, $encounter['probabilityMillionths']);
            self::assertIsArray($encounter['drops']);
            self::assertCount(3, $encounter['drops']);
            self::assertIsArray($encounter['drops'][0]);
            self::assertSame(5, $encounter['drops'][0]['quantity']);
            if (0 === $index) {
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

    /** #321 : un équipement au slot retiré n'est ni tiré ni annoncé, la ressource reste. */
    public function testTheDrawIgnoresEquipmentOnARetiredSlot(): void
    {
        $rules = AlamOutcomeTest::rules()->values;
        $rules['equipment_chance_permille'] = 1000;
        $rules['legendary_chance_permille'] = 0;
        $snapshot = ['alam' => $rules, 'items' => [['key' => 'NAFS_ESSENCE', 'kind' => 'RESOURCE', 'rarity' => 'COMMON'], ['key' => 'IRON_GAUNTLETS', 'kind' => 'EQUIPMENT', 'slot' => 'HANDS', 'rarity' => 'RARE']]];
        $now = new DateTimeImmutable('2026-09-14 10:00:00 UTC');
        $run = new AlamRun(Uuid::v7(), AlamMode::Manual, $now->modify('-1 day'), $now, $now, 1, 'avant-41b2027', $snapshot);
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('https://example.test/placeholder.png');
        $resolution = new AlamResolution($this->createStub(AlamRewards::class), $urls);
        $targets = AlamOutcomeTest::rules()->targets(1);
        $participants = [['playerId' => Uuid::v7()->toRfc4122(), 'displayName' => 'A', 'avatarUrl' => null, 'contribution' => 400, 'gauges' => AlamResolution::gauges($targets, $targets)]];
        $result = $resolution->resolve($run, $participants, $targets, $now);
        self::assertIsArray($result['encounters']);
        self::assertCount(1, $result['encounters']);
        foreach ($result['encounters'] as $encounter) {
            self::assertIsArray($encounter);
            self::assertTrue($encounter['won']);
            self::assertIsArray($encounter['drops']);
            self::assertSame(['NAFS_ESSENCE'], array_column($encounter['drops'], 'itemKey'));
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
            self::assertCount(1, $result['encounters']);
            self::assertIsArray($result['encounters'][0]);
            self::assertSame(0 === $position ? ['idle' => 'https://example.test/a.png', 'attack' => 'https://example.test/b.png', 'hit' => 'https://example.test/c.png'] : null, $result['encounters'][0]['imageUrls']);
        }
    }
}
