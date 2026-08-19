<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `GuildActivityNotifier` (#133) : les annonces de guilde en attente d'être envoyées.
 *
 * Une ligne par auteur, jamais par séance — c'est elle qui porte l'agrégation d'un lot
 * entier de séances créditées en une seule notification. Voir le docblock de
 * `App\Community\Domain\PendingGuildActivity`.
 *
 * **Amputée à la main**, comme les migrations précédentes de ce module : le diff proposait
 * aussi de supprimer l'index partiel `uniq_community_invite_code_live` et la contrainte
 * composite `fk_player_active_title_unlocked` — deux écritures que Doctrine ne sait pas
 * relire dans le mapping et croit donc en trop à chaque génération, sans rapport avec ce
 * ticket.
 */
final class Version20260819153334 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Community : annonces de guilde en attente (#133)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE community_pending_guild_activity (author_id UUID NOT NULL, sessions_count INT NOT NULL, total_xp_granted INT NOT NULL, last_discipline VARCHAR(16) NOT NULL, last_duration_seconds INT NOT NULL, PRIMARY KEY (author_id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE community_pending_guild_activity');
    }
}
