<?php

declare(strict_types=1);

namespace App\Training\Infrastructure\Scheduler;

use App\Training\Application\PurgeLocationEvidence;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('location_evidence')]
final class LocationEvidenceSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return new Schedule()->with(RecurringMessage::every('1 minute', new PurgeLocationEvidence()));
    }
}
