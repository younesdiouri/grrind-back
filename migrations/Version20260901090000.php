<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `SessionCreditedNotifier` (#252) : les notifications « Bien joué ! » en attente d'être
 * envoyées à l'auteur d'une séance créditée.
 *
 * Une ligne par joueur, jamais par séance — même geste que
 * `community_pending_guild_activity` (#133), voir le docblock de
 * `App\Shared\Domain\Notification\PendingSessionCredit`. `window_id` et `opened_at` sont
 * posés dès la création de la table, contrairement à `community_pending_guild_activity` qui
 * les a gagnés dans une migration séparée (#134) : cette table-ci n'existait pas encore
 * quand ce garde-fou a été retenu, elle n'a donc rien à rattraper.
 *
 * **Amputée à la main**, comme les migrations précédentes de ce module : le diff proposait
 * aussi de supprimer l'index partiel `uniq_community_invite_code_live` et la contrainte
 * composite `fk_player_active_title_unlocked` — deux écritures que Doctrine ne sait pas
 * relire dans le mapping et croit donc en trop à chaque génération, sans rapport avec ce
 * ticket.
 */
final class Version20260901090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shared : notifications « Bien joué ! » en attente d\'envoi (#252)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE shared_pending_session_credit (player_id UUID NOT NULL, window_id UUID NOT NULL, opened_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, sessions_count INT NOT NULL, total_xp_granted INT NOT NULL, last_discipline VARCHAR(16) NOT NULL, last_duration_seconds INT NOT NULL, initial_level INT NOT NULL, current_level INT NOT NULL, PRIMARY KEY (player_id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE shared_pending_session_credit');
    }
}
