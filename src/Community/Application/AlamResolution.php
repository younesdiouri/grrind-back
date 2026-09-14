<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\AlamAction;
use App\Community\Domain\AlamNarrationSource;
use App\Community\Domain\AlamOutcome;
use App\Community\Domain\AlamRun;
use App\Shared\Application\AlamRewards;
use App\Shared\Application\FrozenGameRulesets;
use App\Shared\Domain\Alam\AlamRules;
use DateTimeImmutable;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/** Le récit est construit depuis des actions arbitrées, puis les mêmes faits produisent le loot et les souvenirs. */
final readonly class AlamResolution
{
    public function __construct(private AlamRewards $rewards, private UrlGeneratorInterface $urls)
    {
    }

    /**
     * @param list<array{playerId: string, displayName: string, avatarUrl: ?string, contribution: int, gauges: list<array{attribute: string, current: int, target: int, progressPermille: int}>}> $participants
     * @param array<string, int>                                                                                                                                                                  $totals
     *
     * @return array<string, mixed>
     */
    public function resolve(AlamRun $run, array $participants, array $totals, DateTimeImmutable $now): array
    {
        /** @var array<string, mixed> $configuration */
        $configuration = $run->rules['alam'];
        $rules = new AlamRules($configuration);
        $outcomes = new AlamOutcome($rules);
        $seed = hex2bin($run->seed);
        \assert(false !== $seed);
        $random = new Randomizer(new Xoshiro256StarStar($seed));
        $frozen = new FrozenGameRulesets($run->rules, $run->rulesetVersion);
        $catalog = $this->catalog($run);
        $encounters = [];
        $events = [];
        $active = array_filter($participants, static fn (array $player): bool => $player['contribution'] > 0);
        $average = [] === $active ? 1 : array_sum(array_column($active, 'contribution')) / \count($active);
        $duration = $rules->integer('presentation_seconds') * 1000;
        foreach ($rules->thresholds() as $position => $threshold) {
            $index = $position + 1;
            $offset = intdiv($duration * $position, 3);
            $targets = $rules->targets($run->frozenTargetCount, $threshold);
            $probability = $outcomes->probabilityMillionths($totals, $targets);
            $won = $random->getInt(1, 1000000) <= $probability;
            $progress = $outcomes->progressionPermille($totals, $targets);
            $events[] = self::event($run, \count($events), $offset, $index, AlamAction::Arrival, null, null, 'Al-Kasal se dresse devant la guilde.');
            $drops = [];
            foreach ($participants as $playerPosition => $player) {
                $playerId = $player['playerId'];
                if ($player['contribution'] <= 0) {
                    continue;
                }
                $effortOffset = $offset + 1000 + intdiv((intdiv($duration, 3) - 4000) * $playerPosition, max(1, \count($participants)));
                $events[] = self::event($run, \count($events), (int) $effortOffset, $index, AlamAction::Effort, $playerId, null, $player['displayName'].' apporte ses efforts de la semaine.');
                $items = [$rules->text('resource_key') => $rules->integer('resource_base') + intdiv($rules->integer('resource_progression') * $progress, 1000)];
                $weight = min($rules->integer('activity_cap_permille') / 1000, $player['contribution'] / $average);
                if ($won && $random->getInt(1, 1000000) <= self::weightedChance($rules->integer('equipment_chance_permille'), $weight)) {
                    $key = $this->chooseEquipment($catalog, false, $random);
                    if (null !== $key) {
                        $items[$key] = ($items[$key] ?? 0) + 1;
                    }
                }
                if ($won && 3 === $index && $random->getInt(1, 1000000) <= self::weightedChance($rules->integer('legendary_chance_permille'), $weight)) {
                    $key = $this->chooseEquipment($catalog, true, $random);
                    if (null !== $key) {
                        $items[$key] = ($items[$key] ?? 0) + 1;
                    }
                }
                $this->rewards->grant($run->id, $index, Uuid::fromString($playerId), $items, $now, $frozen);
                foreach ($items as $key => $quantity) {
                    $item = $catalog[$key];
                    $drops[] = ['playerId' => $playerId, 'itemKey' => $key, 'name' => $item['name'], 'kind' => $item['kind'], 'rarity' => $item['rarity'], 'imageUrl' => $this->urls->generate('game_image', ['name' => $item['imagePath']], UrlGeneratorInterface::ABSOLUTE_URL), 'quantity' => $quantity];
                    $events[] = self::event($run, \count($events), $offset + intdiv($duration, 3) - 1000, $index, AlamAction::Drop, $playerId, null, $player['displayName'].' reçoit '.$quantity.' × '.$item['name'].'.');
                }
            }
            $narration = $won ? 'Les efforts de la guilde repoussent Al-Kasal.' : 'Al-Kasal résiste. Chaque effort conserve sa récompense.';
            $events[] = self::event($run, \count($events), $offset + intdiv($duration, 3) - 2000, $index, $won ? AlamAction::Victory : AlamAction::Defeat, null, null, $narration);
            $encounters[] = ['index' => $index, 'enemyKey' => $rules->text('enemy_key'), 'enemyName' => 'Al-Kasal', 'thresholdPermille' => $threshold, 'won' => $won, 'probabilityMillionths' => $probability, 'progressPermille' => $progress, 'drops' => $drops, 'narration' => $narration];
        }
        usort($events, static fn (array $left, array $right): int => $left['offsetMs'] <=> $right['offsetMs']);

        return ['participants' => $participants, 'gauges' => self::gauges($totals, $rules->targets($run->frozenTargetCount)), 'encounters' => $encounters, 'events' => $events, 'narrationSource' => AlamNarrationSource::Local->value, 'presentationStartsAt' => $now->format('c'), 'presentationEndsAt' => $now->modify('+'.$rules->integer('presentation_seconds').' seconds')->format('c')];
    }

    /**
     * @param array<string, int> $totals
     * @param array<string, int> $targets
     *
     * @return list<array{attribute: string, current: int, target: int, progressPermille: int}> */
    public static function gauges(array $totals, array $targets): array
    {
        $gauges = [];
        foreach ($targets as $key => $target) {
            $current = $totals[$key] ?? 0;
            $gauges[] = ['attribute' => $key, 'current' => $current, 'target' => $target, 'progressPermille' => min(1000, intdiv($current * 1000, $target))];
        }

        return $gauges;
    }

    private static function weightedChance(int $basePermille, float $weight): int
    {
        // Un contributeur positif conserve une chance représentable même au dernier millionième.
        return 0 === $basePermille ? 0 : max(1, min(1000000, (int) floor($basePermille * 1000 * $weight)));
    }

    /**
     * @return array<string, array{name: string, kind: string, rarity: string, imagePath: string}> */
    private function catalog(AlamRun $run): array
    {
        /** @var list<array{key: string, kind?: string, rarity: string, active?: bool, image_path?: string, translations?: array{fr?: array{name?: string}}}> $items */
        $items = $run->rules['items'];
        $catalog = [];
        foreach ($items as $item) {
            if (false === ($item['active'] ?? true)) {
                continue;
            }
            $catalog[$item['key']] = ['name' => $item['translations']['fr']['name'] ?? $item['key'], 'kind' => $item['kind'] ?? 'EQUIPMENT', 'rarity' => $item['rarity'], 'imagePath' => $item['image_path'] ?? 'placeholder.png'];
        }

        return $catalog;
    }

    /**
     * @param array<string, array{name: string, kind: string, rarity: string, imagePath: string}> $catalog */
    private function chooseEquipment(array $catalog, bool $legendary, Randomizer $random): ?string
    {
        $keys = array_keys(array_filter($catalog, static fn (array $item): bool => 'EQUIPMENT' === $item['kind'] && (('LEGENDARY' === $item['rarity']) === $legendary)));

        return [] === $keys ? null : $keys[$random->getInt(0, \count($keys) - 1)];
    }

    /**
     * @return array{id: string, offsetMs: int, encounterIndex: int, action: string, actorId: ?string, targetId: ?string, text: string} */
    private static function event(AlamRun $run, int $position, int $offset, int $encounter, AlamAction $action, ?string $actor, ?string $target, string $text): array
    {
        return ['id' => $run->id->toRfc4122().':'.$position, 'offsetMs' => $offset, 'encounterIndex' => $encounter, 'action' => $action->value, 'actorId' => $actor, 'targetId' => $target, 'text' => $text];
    }
}
