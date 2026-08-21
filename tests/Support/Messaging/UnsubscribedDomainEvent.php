<?php

declare(strict_types=1);

namespace App\Tests\Support\Messaging;

use App\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Un `DomainEvent` sans aucun abonné, et qui doit le rester — c'est tout son rôle (#155).
 *
 * Il n'est délibérément pas `WorkoutImported` : le jour où quelqu'un ajoute un abonné à
 * `WorkoutImported`, un test écrit contre lui deviendrait vert sans avoir prouvé quoi que
 * ce soit — plus de cas sans abonné sous la main, donc plus de garantie que la règle
 * tient. Cette classe existe pour rester ce cas, indépendamment de ce qui arrive au reste
 * du vocabulaire d'événements.
 */
final readonly class UnsubscribedDomainEvent implements DomainEvent
{
    public function __construct(private DateTimeImmutable $occurredAt)
    {
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
