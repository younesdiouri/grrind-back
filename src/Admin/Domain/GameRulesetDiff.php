<?php

declare(strict_types=1);

namespace App\Admin\Domain;

/** Les chemins rendent visibles les valeurs modifiées, y compris les suppressions. */
final class GameRulesetDiff
{
    /**
     * @param array<string|int, mixed> $before
     * @param array<string|int, mixed> $after
     *
     * @return list<array{path: string, before: string, after: string}>
     */
    public static function between(array $before, array $after, string $path = ''): array
    {
        $changes = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $left = $before[$key] ?? null;
            $right = $after[$key] ?? null;
            $name = '' === $path ? (string) $key : $path.'.'.$key;
            if (\is_array($left) && \is_array($right)) {
                $changes = [...$changes, ...self::between($left, $right, $name)];
            } elseif ($left !== $right) {
                $changes[] = ['path' => $name, 'before' => json_encode($left, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE), 'after' => json_encode($right, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE)];
            }
        }

        return $changes;
    }
}
