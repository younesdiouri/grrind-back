<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * N'écrit nulle part — elle enregistre chaque appel, pour qu'un test affirme précisément
 * quel niveau et quel contexte une ligne de log a portés (#149). Même esprit que
 * {@see SpyingDeadPushTokens} et {@see SpyingPushSender} pour leurs ports respectifs.
 */
final class SpyingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * `$level` reste `mixed`, sans le typer ni le forcer en chaîne : PSR-3 ne le type pas
     * non plus (voir {@see \Monolog\Logger::log()}), c'est toujours une des constantes de
     * {@see \Psr\Log\LogLevel} en pratique, et un test compare directement `===` contre
     * la chaîne attendue.
     *
     * @param array<string, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
