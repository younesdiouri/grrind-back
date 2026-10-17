<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Shared\Infrastructure\Config\GameRulesetVersion;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/** Phase de dev : remplace la DA Murīd sans modifier progression, séances ou inventaires. */
final class Version20261017200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quatre avatars rétro, leurs ancrages et huit équipements cosmétiques administrables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE game_artwork_pack (id UUID NOT NULL, pack_key VARCHAR(64) NOT NULL, kind VARCHAR(16) NOT NULL, version VARCHAR(32) NOT NULL, name VARCHAR(120) NOT NULL, active BOOLEAN NOT NULL, sort_order INT NOT NULL, manifest_json TEXT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_artwork_pack ON game_artwork_pack (kind, pack_key, version)');
        $this->addSql("ALTER TABLE identity_user ALTER appearance TYPE VARCHAR(64), ALTER appearance SET DEFAULT 'STRENGTH_V1'");
        $this->addSql("UPDATE identity_user SET appearance = 'STRENGTH_V1' WHERE appearance = 'MURID'");
        $source = file_get_contents(__DIR__.'/data/retro-v1.json');
        \assert(false !== $source);
        /** @var list<array{kind: string, key: string, version: string, name: string, active: bool, sortOrder: int, manifest: array<string, mixed>}> $packs */
        $packs = json_decode($source, true, flags: \JSON_THROW_ON_ERROR);
        $published = $this->connection->fetchAssociative('SELECT revision, snapshot FROM game_ruleset WHERE id = 1');
        \assert(false !== $published);
        /** @var array<string, mixed> $snapshot */
        $snapshot = json_decode((string) $published['snapshot'], true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($snapshot['items']));
        $sort = (int) $this->connection->fetchOne('SELECT COALESCE(MAX(sort_order), 0) FROM game_item');
        foreach ($packs as $pack) {
            $this->insert('game_artwork_pack', [
                'id' => Uuid::v7()->toRfc4122(), 'pack_key' => $pack['key'], 'kind' => $pack['kind'],
                'version' => $pack['version'], 'name' => $pack['name'], 'active' => $pack['active'],
                'sort_order' => $pack['sortOrder'], 'manifest_json' => json_encode($pack['manifest'], \JSON_THROW_ON_ERROR),
            ]);
            if ('EQUIPMENT' !== $pack['kind']) {
                continue;
            }
            $translations = ['fr' => ['name' => $pack['name']], 'en' => ['name' => $pack['name']]];
            $this->insert('game_item', [
                'id' => Uuid::v7()->toRfc4122(), 'item_key' => $pack['key'], 'active' => true,
                'ever_published_active' => true, 'sort_order' => ++$sort, 'rarity' => 'COMMON',
                'kind' => 'EQUIPMENT', 'slot' => $pack['manifest']['slot'], 'price_coins' => 0,
                'sell_price_coins' => 0, 'modifiers' => '[]', 'shop_available' => true,
                'shop_minimum_level' => null, 'image_path' => 'placeholder.png',
                'translations' => json_encode($translations, \JSON_THROW_ON_ERROR),
            ]);
            // Ajout ciblé au publié : aucun brouillon de balance en attente n’est publié.
            $snapshot['items'][] = [
                'key' => $pack['key'], 'active' => true, 'rarity' => 'COMMON', 'kind' => 'EQUIPMENT',
                'slot' => $pack['manifest']['slot'], 'price_coins' => 0, 'sell_price_coins' => 0,
                'modifiers' => [], 'shop' => ['available' => true, 'minimum_level' => null],
                'image_path' => 'placeholder.png', 'translations' => $translations,
            ];
        }
        $version = GameRulesetVersion::of($snapshot);
        $encoded = json_encode($snapshot, \JSON_THROW_ON_ERROR);
        $revision = (int) $published['revision'] + 1;
        $this->addSql('UPDATE game_ruleset SET snapshot = ?, version = ?, revision = ?, draft_revision = draft_revision + 1, published_at = CURRENT_TIMESTAMP WHERE id = 1', [$encoded, $version, $revision]);
        $this->insert('game_publication', ['id' => Uuid::v7()->toRfc4122(),
            'published_at' => date('Y-m-d H:i:sP'), 'revision' => $revision, 'author' => 'retro-avatar-art',
            'version' => $version, 'snapshot' => $encoded]);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('La DA Murīd a été retirée ; restaurer une sauvegarde pour revenir à cette version.');
    }

    /** @param array<string, mixed> $values */
    private function insert(string $table, array $values): void
    {
        $columns = implode(', ', array_keys($values));
        $parameters = implode(', ', array_fill(0, \count($values), '?'));
        $this->addSql("INSERT INTO {$table} ({$columns}) VALUES ({$parameters})", array_values($values));
    }
}
