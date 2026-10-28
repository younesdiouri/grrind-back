<?php

declare(strict_types=1);

namespace App\Training\Application;

use App\Shared\Domain\Event\WorkoutImported;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'event.bus')]
final readonly class MatchTogetherOnImport
{
    public function __construct(private TogetherTracker $tracker)
    {
    }

    public function __invoke(WorkoutImported $event): void
    {
        $this->tracker->imported($event->workoutId);
    }
}
