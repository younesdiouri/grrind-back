<?php

declare(strict_types=1);

namespace App\Community\Application;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class AdvanceAlamHandler
{
    public function __construct(private AlamRuns $runs)
    {
    }

    public function __invoke(AdvanceAlam $message): void
    {
        $this->runs->tick();
    }
}
