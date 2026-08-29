<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le module Combat : la ligne d'un combat PvE joué (#211).
 *
 * **Amputée à la main**, comme toutes les migrations de ce dépôt. Le diff proposait en plus
 * de recréer quatre artefacts déjà décrits dans les migrations précédentes et qu'il ne sait
 * pas relire depuis le mapping : l'index partiel `uniq_community_invite_code_live`, l'index
 * partiel `uniq_community_risala_open_turn` (identique, seulement retextifié), la contrainte
 * composite `fk_player_active_title_unlocked` avec son index. Rien de tout ça ne vient de ce
 * ticket ; les appliquer aurait cassé trois garde-fous d'un autre module sans qu'aucun test
 * ne le voie ici.
 *
 * `player_id` est un UUID nu, sans clé étrangère : `Combat` ne connaît pas `Identity`, et
 * Deptrac l'interdirait même si la base le permettait — voir le docblock de
 * `App\Combat\Domain\Battle`.
 *
 * `player_snapshot`, `enemy_snapshot` et `timeline` sont `JSONB` plutôt que `JSON` : c'est
 * ce que rend `Doctrine\DBAL\Types\Types::JSONB`, un type natif de DBAL, sans type custom à
 * déclarer dans `doctrine.yaml`.
 */
final class Version20260829091957 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Combat : la ligne d\'un combat PvE joué';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE combat_battle (id UUID NOT NULL, player_id UUID NOT NULL, player_snapshot JSONB NOT NULL, enemy_snapshot JSONB NOT NULL, result VARCHAR(16) NOT NULL, timeline JSONB NOT NULL, seed VARCHAR(64) NOT NULL, ruleset_version VARCHAR(32) NOT NULL, turns INT NOT NULL, fought_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');

        // L'historique d'un joueur part toujours de lui : sans cet index, le lister ferait
        // un parcours séquentiel de tous les combats de tout le monde.
        $this->addSql('CREATE INDEX idx_combat_battle_player ON combat_battle (player_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE combat_battle');
    }
}
