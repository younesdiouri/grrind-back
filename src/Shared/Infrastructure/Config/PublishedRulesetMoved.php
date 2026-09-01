<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Config;

use LogicException;

/** Le pointeur a bougé entre sa lecture et le chargement du snapshot attendu. */
final class PublishedRulesetMoved extends LogicException
{
}
