<?php

declare(strict_types=1);

namespace App\Training\Application;

use App\Shared\Application\PlayerTimezones;
use App\Training\Domain\StatsPeriod;
use App\Training\Domain\WorkoutStats;
use App\Training\Domain\WorkoutStatsWindow;
use App\Training\Infrastructure\Doctrine\WorkoutRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class WorkoutStatsProvider
{
    public function __construct(
        private WorkoutRepository $workouts,
        private PlayerTimezones $timezones,
        private ClockInterface $clock,
    ) {
    }

    public function of(Uuid $userId, StatsPeriod $period): WorkoutStats
    {
        $timezone = $this->timezones->of($userId);
        $window = WorkoutStatsWindow::containing($period, $this->clock->now(), $timezone);

        return new WorkoutStats($period, $timezone, $window, $this->workouts->statsByLocalDay($userId, $window, $timezone));
    }
}
