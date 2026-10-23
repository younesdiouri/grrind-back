<?php

declare(strict_types=1);

namespace App\Admin\UI\Console;

use App\Admin\Infrastructure\GameRulesetPublisher;
use App\Shared\Infrastructure\Config\GameRulesetVersion;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use LogicException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Retrait destructif explicite du catalogue alpha (#315), jamais une migration.
 * Les 17 clés sont figées : relancer la commande ne supprime aucun futur équipement.
 * Possessions supprimées sans remboursement ; XP, pièces, audits et archives immuables.
 * Le publié et le brouillon sont corrigés séparément, sans publier une édition en attente.
 * Console Symfony : https://symfony.com/doc/current/console.html.
 *
 * @phpstan-import-type Snapshot from GameRulesetPublisher
 */
#[AsCommand(name: 'app:equipment:retire-legacy', description: 'Retire les équipements sans 3D et déplace les gants vers Arme (simulation par défaut).')]
final class RetireLegacyEquipmentCommand extends Command
{
    /** @var list<string> */
    private const array RETIRED = [
        'WORN_RUNNING_SHOES', 'IRON_GAUNTLETS', 'TRAVELERS_CLOAK', 'LUCKY_RABBIT_CHARM',
        'TEMPERED_HEADBAND', 'SWIFT_TRAIL_LEGGINGS', 'EMBER_OF_THE_ANCESTORS',
        'OBSIDIAN_WARBLADE', 'STORMCALLERS_BOOTS', 'CROWN_OF_THE_TIRELESS',
        'PURSUIT_GOGGLES', 'QUIET_STRENGTH_BELT', 'ALL_PURPOSE_FLASK', 'HEADACHE_CAP',
        'GENIE_CHALK_BAG', 'MIDNIGHT_SWIM_CAP', 'ACADEMIC_FLIPPERS',
    ];

    public function __construct(private readonly Connection $db, private readonly GameRulesetPublisher $publisher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Applique le nettoyage ; sans cette option, toutes les écritures sont annulées.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $apply = true === $input->getOption('apply');
        $this->db->beginTransaction();
        try {
            // Les éditions admin et les écritures d'inventaire en cours terminent avant le retrait.
            $this->db->executeStatement('LOCK TABLE rewards_inventory_item IN EXCLUSIVE MODE');
            $row = $this->db->fetchAssociative('SELECT revision, snapshot FROM game_ruleset WHERE id = 1 FOR UPDATE');
            if (false === $row || !\is_string($row['snapshot'])) {
                throw new LogicException('Le catalogue publié est absent.');
            }
            /** @var Snapshot $snapshot */
            $snapshot = json_decode($row['snapshot'], true, flags: \JSON_THROW_ON_ERROR);
            $before = $snapshot;
            $snapshot['items'] = array_values(array_filter($snapshot['items'], static fn (array $item): bool => !\in_array($item['key'], self::RETIRED, true)));
            foreach ($snapshot['items'] as &$item) {
                if ('GOLDEN_BOXING_GLOVES' === $item['key']) {
                    $item['slot'] = 'WEAPON';
                }
            }
            unset($item);
            foreach (['workout', 'adversary', 'chest'] as $kind) {
                \assert(\is_array($snapshot['loot'][$kind]));
                foreach ($snapshot['loot'][$kind] as &$table) {
                    \assert(\is_array($table) && \is_array($table['entries']));
                    /** @var list<array{item?: string, weight: int}> $entries */
                    $entries = $table['entries'];
                    $table['entries'] = self::entries($entries);
                }
                unset($table);
            }
            $snapshot['recipes'] = array_values(array_filter($snapshot['recipes'] ?? [], static function (array $recipe): bool {
                \assert(\is_array($recipe['costs']));

                return !\in_array($recipe['result_item'], self::RETIRED, true) && [] === array_intersect(array_keys($recipe['costs']), self::RETIRED);
            }));

            $params = [self::RETIRED];
            $types = [ArrayParameterType::STRING];
            $units = $this->db->fetchOne('SELECT COALESCE(SUM(quantity), 0) FROM rewards_inventory_item WHERE item_key IN (?)', $params, $types);
            \assert(\is_int($units) || \is_string($units));
            $units = (int) $units;
            /** @var array<string, int> $counts */
            $counts = [];
            $counts['inventoryStacks'] = (int) $this->db->executeStatement('DELETE FROM rewards_inventory_item WHERE item_key IN (?)', $params, $types);
            $counts['glovesMovedToWeapon'] = (int) $this->db->executeStatement("UPDATE rewards_inventory_item g SET slot = 'WEAPON' WHERE g.item_key = 'GOLDEN_BOXING_GLOVES' AND g.slot = 'HANDS' AND g.quantity > 0 AND NOT EXISTS (SELECT 1 FROM rewards_inventory_item w WHERE w.user_id = g.user_id AND w.slot = 'WEAPON')");
            $counts['handsClearedToBag'] = (int) $this->db->executeStatement("UPDATE rewards_inventory_item SET slot = NULL WHERE slot = 'HANDS'");
            $counts['catalogItems'] = (int) $this->db->executeStatement('DELETE FROM game_item WHERE item_key IN (?)', $params, $types);
            $counts['glovesSlotChanged'] = (int) $this->db->executeStatement("UPDATE game_item SET slot = 'WEAPON' WHERE item_key = 'GOLDEN_BOXING_GLOVES' AND slot IS DISTINCT FROM 'WEAPON'");
            $counts['artworkDeactivated'] = (int) $this->db->executeStatement("UPDATE game_artwork_pack SET active = false WHERE kind = 'EQUIPMENT' AND active = true AND pack_key IN (?)", $params, $types);
            $counts['lootTables'] = 0;
            foreach ($this->db->fetchAllAssociative('SELECT id, entries FROM game_loot_table') as $table) {
                \assert(\is_string($table['entries']));
                /** @var list<array{item?: string, weight: int}> $entries */
                $entries = json_decode($table['entries'], true, flags: \JSON_THROW_ON_ERROR);
                $clean = self::entries($entries);
                if ($entries !== $clean) {
                    $counts['lootTables'] += (int) $this->db->executeStatement('UPDATE game_loot_table SET entries = ? WHERE id = ?', [json_encode($clean, \JSON_THROW_ON_ERROR), $table['id']]);
                }
            }
            $counts['recipes'] = 0;
            foreach ($this->db->fetchAllAssociative('SELECT id, result_item, costs FROM game_recipe') as $recipe) {
                \assert(\is_string($recipe['costs']));
                /** @var list<array{item: string}> $costs */
                $costs = json_decode($recipe['costs'], true, flags: \JSON_THROW_ON_ERROR);
                if (\in_array($recipe['result_item'], self::RETIRED, true) || [] !== array_intersect(array_column($costs, 'item'), self::RETIRED)) {
                    $counts['recipes'] += (int) $this->db->executeStatement('DELETE FROM game_recipe WHERE id = ?', [$recipe['id']]);
                }
            }
            $counts['designProfiles'] = 0;
            /** @var list<string> $otherWeapons */
            $otherWeapons = $this->db->fetchFirstColumn("SELECT item_key FROM game_item WHERE slot = 'WEAPON' AND item_key <> 'GOLDEN_BOXING_GLOVES'");
            foreach ($this->db->fetchAllAssociative('SELECT id, equipment FROM game_design_profile') as $profile) {
                \assert(\is_string($profile['equipment']));
                /** @var list<string> $equipment */
                $equipment = json_decode($profile['equipment'], true, flags: \JSON_THROW_ON_ERROR);
                $clean = array_values(array_diff($equipment, self::RETIRED));
                // Un profil ne possède pas de sac : l'arme déjà présente garde sa place.
                if ([] !== array_intersect($clean, $otherWeapons)) {
                    $clean = array_values(array_diff($clean, ['GOLDEN_BOXING_GLOVES']));
                }
                if ($clean !== $equipment) {
                    $counts['designProfiles'] += (int) $this->db->executeStatement('UPDATE game_design_profile SET equipment = ? WHERE id = ?', [json_encode($clean, \JSON_THROW_ON_ERROR), $profile['id']]);
                }
            }

            $changed = $snapshot !== $before || array_sum($counts) > 0;
            \assert(\is_int($row['revision']) || \is_string($row['revision']));
            $revision = (int) $row['revision'];
            if ($changed) {
                if ($snapshot['loot'] !== $before['loot']) {
                    \assert(\is_int($snapshot['loot']['version']));
                    ++$snapshot['loot']['version'];
                }
                if ($counts['lootTables'] > 0) {
                    $this->db->executeStatement('UPDATE game_settings SET loot_version = loot_version + 1 WHERE id = 1');
                }
                GameRulesetPublisher::validate($snapshot);
                $encoded = json_encode($snapshot, \JSON_THROW_ON_ERROR);
                $version = GameRulesetVersion::of($snapshot);
                ++$revision;
                $this->db->executeStatement('UPDATE game_ruleset SET snapshot = ?, version = ?, revision = ?, draft_revision = draft_revision + 1, published_at = CURRENT_TIMESTAMP WHERE id = 1', [$encoded, $version, $revision]);
                $this->db->executeStatement('INSERT INTO game_publication (id, published_at, revision, author, version, snapshot) VALUES (?, CURRENT_TIMESTAMP, ?, ?, ?, ?)', [Uuid::v7()->toRfc4122(), $revision, 'retire-legacy-equipment-315', $version, $encoded]);
            }
            if ($apply) {
                $this->db->commit();
            } else {
                $this->db->rollBack();
            }
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
        if ($apply && $changed) {
            $this->publisher->invalidateAfterCommit();
        }
        $output->writeln(json_encode(['applied' => $apply, 'changed' => $changed, 'retiredKeys' => self::RETIRED, 'inventoryUnits' => $units, ...$counts, 'publicationRevision' => $revision], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));

        return Command::SUCCESS;
    }

    /**
     * @param list<array{item?: string, weight: int}> $entries
     *
     * @return list<array{item?: string, weight: int}>
     */
    private static function entries(array $entries): array
    {
        return array_values(array_filter($entries, static fn (array $entry): bool => !\in_array($entry['item'] ?? null, self::RETIRED, true))) ?: [['weight' => 1]];
    }
}
