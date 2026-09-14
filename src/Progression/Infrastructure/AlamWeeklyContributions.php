<?php

declare(strict_types=1);

namespace App\Progression\Infrastructure;

use App\Progression\Domain\DailyLoad;
use App\Progression\Domain\DiminishingReturns;
use App\Progression\Domain\LevelCurve;
use App\Progression\Domain\XpCalculator;
use App\Progression\Domain\XpRates;
use App\Progression\Infrastructure\Doctrine\ProgressionSnapshotRepository;
use App\Shared\Application\AlamContributions;
use App\Shared\Application\AlamEfforts;
use App\Shared\Application\GameRulesets;
use App\Shared\Application\PlayerTimezones;
use App\Shared\Domain\Activity\AttributeGains;
use App\Shared\Domain\Activity\AttributeSplit;
use App\Shared\Domain\Activity\Vitality;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/** Rejoue les sources non invalidées dans l'ordre sportif, sans modificateurs et sans lire un total de personnage. */
final readonly class AlamWeeklyContributions implements AlamContributions
{
    public function __construct(private Connection $connection, private AlamEfforts $efforts, private PlayerTimezones $timezones, private ProgressionSnapshotRepository $snapshots)
    {
    }

    public function between(array $players, DateTimeImmutable $start, DateTimeImmutable $end, GameRulesets $rules): array
    {
        $calculator = new XpCalculator(XpRates::runtime($rules), DiminishingReturns::runtime($rules), AttributeSplit::runtime($rules), $rules);
        $vitality = Vitality::runtime($rules);
        $result = [];
        foreach ($players as $player) {
            /** @var list<string> $ids */
            $ids = $this->connection->fetchFirstColumn('SELECT source_id FROM xp_transaction WHERE user_id = :player AND occurred_at < :until GROUP BY source_id HAVING SUM(duration_seconds) > 0 ORDER BY MIN(occurred_at), source_id', ['player' => $player->toRfc4122(), 'until' => $end->format('c')]);
            $totals = ['strength' => 0, 'endurance' => 0, 'mobility' => 0, 'dexterity' => 0];
            $secondsByDay = [];
            $xpByDay = [];
            $sessions = 0;
            $sports = [];
            $zone = $this->timezones->of($player)->toDateTimeZone();
            foreach ($this->efforts->within($ids, $start, $end, $rules) as $effort) {
                $day = $effort->start->setTimezone($zone)->format('Y-m-d');
                $discipline = $effort->discipline->value;
                $award = $calculator->calculate($effort->discipline, $effort->seconds, [], new DailyLoad($secondsByDay[$day] ?? 0, $xpByDay[$day][$discipline] ?? 0), $effort->distance, $effort->elevation);
                $secondsByDay[$day] = ($secondsByDay[$day] ?? 0) + $effort->seconds;
                $xpByDay[$day][$discipline] = ($xpByDay[$day][$discipline] ?? 0) + $award->amount();
                foreach ($award->attributeGains->toArray() as $attribute => $value) {
                    $totals[$attribute] += $value;
                }
                $sports[$discipline] ??= ['discipline' => $discipline, 'sessions' => 0, 'durationSeconds' => 0];
                ++$sports[$discipline]['sessions'];
                $sports[$discipline]['durationSeconds'] += $effort->seconds;
                ++$sessions;
            }
            $gains = new AttributeGains($totals['strength'], $totals['endurance'], $totals['mobility'], $totals['dexterity']);
            $totals['vitality'] = $vitality->of($gains);
            $result[$player->toRfc4122()] = ['attributes' => $totals, 'total' => $gains->total(), 'sessions' => $sessions, 'sports' => array_values($sports)];
        }

        return $result;
    }

    public function lock(array $players, GameRulesets $rules): void
    {
        usort($players, static fn ($left, $right): int => strcmp($left->toRfc4122(), $right->toRfc4122()));
        foreach ($players as $player) {
            $this->snapshots->lockFor($player, LevelCurve::runtime($rules), Vitality::runtime($rules));
        }
    }

    public function activeCount(array $players, DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        $active = 0;
        foreach ($players as $player) {
            $count = $this->connection->fetchOne('SELECT COUNT(*) FROM (SELECT source_id FROM xp_transaction WHERE user_id = :player AND occurred_at >= :start AND occurred_at < :until GROUP BY source_id HAVING SUM(amount) > 0) credited', ['player' => $player->toRfc4122(), 'start' => $start->format('c'), 'until' => $end->format('c')]);
            \assert(\is_int($count) || \is_string($count));
            if ((int) $count > 0) {
                ++$active;
            }
        }

        return max(1, $active);
    }
}
