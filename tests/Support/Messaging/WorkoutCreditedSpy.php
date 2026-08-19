<?php

declare(strict_types=1);

namespace App\Tests\Support\Messaging;

use App\Shared\Domain\Event\WorkoutCredited;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Le pendant de {@see WorkoutImportedSpy} pour `WorkoutCredited` (#128) : un module tiers
 * en réduction, qui prouve qu'on peut réagir à ce qu'une séance a rapporté sans qu'aucune
 * ligne de `Progression` ne le mentionne.
 */
#[AsMessageHandler]
final class WorkoutCreditedSpy
{
    /** @var list<WorkoutCredited> */
    public static array $received = [];

    public function __invoke(WorkoutCredited $event): void
    {
        self::$received[] = $event;
    }

    public static function forget(): void
    {
        self::$received = [];
    }
}
