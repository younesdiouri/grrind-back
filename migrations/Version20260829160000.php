<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * L'historique d'un joueur (#220) : `idx_combat_battle_player` sur `player_id` seul retrouvait
 * ses combats, mais ne servait pas à les **paginer** — chaque page aurait forcé Postgres à
 * trier `fought_at, id` à la volée. Le remplacement porte les trois colonnes dans l'ordre du
 * tri de `BattleRepository::history()`, `DESC` sur les deux dernières : l'index sert la
 * requête telle qu'écrite, sans tri à part.
 *
 * Un `DROP` puis un `CREATE`, jamais un `ALTER` : PostgreSQL n'a pas de syntaxe pour étendre
 * un index existant, et le diff Doctrine généré à l'aveugle aurait proposé la même paire —
 * relue ici plutôt que produite en confiance.
 */
final class Version20260829160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Combat : idx_combat_battle_player devient un index de pagination (player_id, fought_at DESC, id DESC)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_combat_battle_player');
        $this->addSql('CREATE INDEX idx_combat_battle_player ON combat_battle (player_id, fought_at DESC, id DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_combat_battle_player');
        $this->addSql('CREATE INDEX idx_combat_battle_player ON combat_battle (player_id)');
    }
}
