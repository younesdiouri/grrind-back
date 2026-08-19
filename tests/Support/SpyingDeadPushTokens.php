<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Application\DeadPushTokens;

/**
 * N'efface rien nulle part — elle enregistre ce qu'on lui a demandé d'effacer, pour
 * qu'un test affirme précisément quel jeton a été jugé mort (#131).
 *
 * Une classe nommée plutôt qu'anonyme : {@see DeadPushTokens} ne porte pas `$discarded`,
 * et un facteur qui rendrait le type de l'interface effacerait la propriété aux yeux de
 * PHPStan à l'appel.
 */
final class SpyingDeadPushTokens implements DeadPushTokens
{
    /** @var list<string> */
    public array $discarded = [];

    public function discard(string $pushToken): void
    {
        $this->discarded[] = $pushToken;
    }
}
