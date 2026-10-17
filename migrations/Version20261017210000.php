<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Shared\Infrastructure\Config\GameRulesetVersion;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/** Les nouveaux cosmétiques respectent le prix strictement positif de la boutique. */
final class Version20261017210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prix symbolique de 1 pièce pour les huit équipements rétro, sans modifier leurs statistiques.';
    }

    public function up(Schema $schema): void
    {
        $keys = ['HALTER_EGO', 'PURSUIT_GOGGLES', 'QUIET_STRENGTH_BELT', 'ALL_PURPOSE_FLASK', 'HEADACHE_CAP', 'GENIE_CHALK_BAG', 'MIDNIGHT_SWIM_CAP', 'ACADEMIC_FLIPPERS'];
        foreach ($keys as $key) {
            $this->addSql('UPDATE game_item SET price_coins = 1 WHERE item_key = ? AND price_coins = 0', [$key]);
        }
        $published = $this->connection->fetchAssociative('SELECT revision, snapshot FROM game_ruleset WHERE id = 1');
        \assert(false !== $published);
        /** @var array{items:list<array<string,mixed>>} $snapshot */
        $snapshot = json_decode((string) $published['snapshot'], true, flags: \JSON_THROW_ON_ERROR);
        $changed = false;
        foreach ($snapshot['items'] as &$item) {
            if (\in_array($item['key'], $keys, true) && 0 === $item['price_coins']) {
                $item['price_coins'] = 1;
                $changed = true;
            }
        }
        unset($item);
        if (!$changed) {
            return;
        }
        $version = GameRulesetVersion::of($snapshot);
        $encoded = json_encode($snapshot, \JSON_THROW_ON_ERROR);
        $revision = (int) $published['revision'] + 1;
        $this->addSql('UPDATE game_ruleset SET snapshot = ?, version = ?, revision = ?, draft_revision = draft_revision + 1, published_at = CURRENT_TIMESTAMP WHERE id = 1', [$encoded, $version, $revision]);
        $this->addSql('INSERT INTO game_publication (id, published_at, revision, author, version, snapshot) VALUES (?, CURRENT_TIMESTAMP, ?, ?, ?, ?)', [Uuid::v7()->toRfc4122(), $revision, 'retro-cosmetic-price', $version, $encoded]);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Le prix nul ne respecte pas le catalogue livré.');
    }
}
