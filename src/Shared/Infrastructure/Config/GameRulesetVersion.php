<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Config;

/**
 * L'empreinte d'une révision est calculable côté lecture comme côté publication. Ainsi un
 * déploiement qui change un YAML restant ne continue jamais à annoncer l'ancienne version
 * tant qu'aucune édition EasyAdmin n'a lieu.
 */
final class GameRulesetVersion
{
    /** @param array<string, mixed> $snapshot */
    public static function of(string $yamlVersion, array $snapshot): string
    {
        $snapshot = self::gameplay($snapshot);
        $canonical = json_encode(self::canonicalize(['yaml_version' => $yamlVersion, 'database' => $snapshot]), \JSON_THROW_ON_ERROR);

        return 'v1-'.substr(hash('sha256', $canonical), 0, 12);
    }

    /**
     * @param array<int|string, mixed> $values
     *
     * @return array<int|string, mixed>
     */
    private static function canonicalize(array $values): array
    {
        if (!array_is_list($values)) {
            ksort($values);
        }
        foreach ($values as $key => $value) {
            if (\is_array($value)) {
                $values[$key] = self::canonicalize($value);
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $snapshot
     *
     * @return array<string, mixed>
     */
    private static function gameplay(array $snapshot): array
    {
        $items = $snapshot['items'] ?? [];
        \assert(\is_array($items));
        foreach ($items as &$item) {
            \assert(\is_array($item));
            unset($item['image_path'], $item['translations']);
        }
        unset($item);
        $snapshot['items'] = $items;
        $titles = $snapshot['titles'] ?? [];
        \assert(\is_array($titles));
        foreach ($titles as &$title) {
            \assert(\is_array($title));
            unset($title['translations']);
        }
        unset($title);
        $snapshot['titles'] = $titles;
        $combat = $snapshot['combat'] ?? [];
        \assert(\is_array($combat));
        foreach (['enemies', 'bosses'] as $type) {
            $enemies = $combat[$type] ?? [];
            \assert(\is_array($enemies));
            foreach ($enemies as &$enemy) {
                \assert(\is_array($enemy));
                unset($enemy['translations']);
            }
            unset($enemy);
            $combat[$type] = $enemies;
        }
        $snapshot['combat'] = $combat;

        return $snapshot;
    }
}
