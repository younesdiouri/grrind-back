<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Infrastructure\Config\GameRulesetVersion;
use Doctrine\DBAL\Connection;

/**
 * Le catalogue de test conserve ses paliers, bonus d'XP et raretés pour éprouver le moteur
 * administrable. Ces données de scénario ne sont pas le catalogue Unity de production.
 * Les migrations historiques restent figées ; leurs équipements utilisent ici les six
 * emplacements actuels, sans compatibilité HANDS dans le domaine métier (#315).
 */
final class EquipmentCatalogFixture
{
    /**
     * @param array<string, mixed> $snapshot
     *
     * @return array<string, mixed>
     */
    public static function snapshot(array $snapshot): array
    {
        /** @var list<array<string, mixed>> $items */
        $items = $snapshot['items'];
        foreach ($items as &$item) {
            if ('HANDS' === ($item['slot'] ?? null)) {
                $item['slot'] = 'WEAPON';
            }
        }
        unset($item);
        $snapshot['items'] = $items;

        return $snapshot;
    }

    public static function install(Connection $db): void
    {
        $db->transactional(static function (Connection $db): void {
            $stored = $db->fetchOne('SELECT snapshot FROM game_ruleset WHERE id = 1 FOR UPDATE');
            \assert(\is_string($stored));
            /** @var array<string, mixed> $before */
            $before = json_decode($stored, true, flags: \JSON_THROW_ON_ERROR);
            $snapshot = self::snapshot($before);
            $db->executeStatement("UPDATE game_item SET slot = 'WEAPON' WHERE slot = 'HANDS'");
            if ($before !== $snapshot) {
                $db->executeStatement('UPDATE game_ruleset SET snapshot = ?, version = ?, revision = revision + 1 WHERE id = 1', [json_encode($snapshot, \JSON_THROW_ON_ERROR), GameRulesetVersion::of($snapshot)]);
            }
        });
    }
}
