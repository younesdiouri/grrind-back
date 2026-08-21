<?php

declare(strict_types=1);

namespace App\Tests\Support\Messaging;

/**
 * Un message quelconque, sans aucun handler et sans entrée dans `routing` (#155) : il
 * n'est ni un `DomainEvent`, ni un déclencheur interne comme `AnnounceGuildActivity`. Sert
 * uniquement à prouver que `command.bus` — contrairement à `event.bus` — garde sa
 * sévérité par défaut : une commande sans handler doit lever, jamais passer en silence.
 */
final class UnsubscribedMessage
{
}
