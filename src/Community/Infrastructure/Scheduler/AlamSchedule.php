<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Scheduler;

use App\Community\Application\AdvanceAlam;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/** Les échéances persistées permettent le rattrapage ; le battement ne porte aucune décision de jeu. */
#[AsSchedule('alam')]
final class AlamSchedule implements ScheduleProviderInterface
{
    private ?Schedule $schedule = null;

    public function getSchedule(): Schedule
    {
        return $this->schedule ??= new Schedule()->with(RecurringMessage::cron('* * * * *', new AdvanceAlam()));
    }
}
