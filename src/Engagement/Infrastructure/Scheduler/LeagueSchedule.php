<?php

declare(strict_types=1);

namespace App\Engagement\Infrastructure\Scheduler;

use App\Engagement\Application\SettleLeagues;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/** Le battement fréquent permet de reprendre après une coupure ; la période en base décide de l'échéance. */
#[AsSchedule('league')]
final class LeagueSchedule implements ScheduleProviderInterface
{
    private ?Schedule $schedule = null;

    public function getSchedule(): Schedule
    {
        return $this->schedule ??= new Schedule()->with(RecurringMessage::cron('* * * * *', new SettleLeagues()));
    }
}
