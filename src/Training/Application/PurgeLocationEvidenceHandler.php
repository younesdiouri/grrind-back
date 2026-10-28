<?php

declare(strict_types=1);

namespace App\Training\Application;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PurgeLocationEvidenceHandler
{
    public function __construct(private TogetherTracker $tracker)
    {
    }

    public function __invoke(PurgeLocationEvidence $message): void
    {
        $this->tracker->purge();
    }
}
