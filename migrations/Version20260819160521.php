<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Idempotence des notifications (#134) : une trace de livraison par (événement,
 * destinataire, catégorie), le `windowId` qui donne à `AnnounceGuildActivity` un
 * identifiant propre à sa fenêtre plutôt que le seul `authorId`, et `opened_at` qui permet
 * de distinguer une fenêtre en vol d'une fenêtre abandonnée — voir le docblock de
 * `App\Shared\Domain\Notification\NotificationDelivery` et celui de
 * `App\Community\Domain\PendingGuildActivity`.
 *
 * **Amputée à la main**, même geste que `Version20260819153334` : le diff propose aussi de
 * supprimer l'index partiel `uniq_community_invite_code_live` et la contrainte composite
 * `fk_player_active_title_unlocked` — deux écritures que Doctrine ne sait pas relire dans
 * le mapping et croit donc en trop à chaque génération, sans rapport avec ce ticket.
 *
 * `window_id` et `opened_at` en `NOT NULL` sans défaut : la table qu'ils rejoignent ne
 * contient que des fenêtres d'agrégation en vol, jamais d'historique à préserver — une
 * ligne encore ouverte au moment du déploiement n'a perdu qu'une annonce en attente, pas
 * une donnée de jeu.
 */
final class Version20260819160521 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shared : trace de livraison des notifications, Community : identité de fenêtre (#134)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE shared_notification_delivery (id UUID NOT NULL, event_id UUID NOT NULL, recipient_id UUID NOT NULL, category VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_shared_notification_delivery ON shared_notification_delivery (event_id, recipient_id, category)');
        $this->addSql('ALTER TABLE community_pending_guild_activity ADD window_id UUID NOT NULL');
        $this->addSql('ALTER TABLE community_pending_guild_activity ADD opened_at TIMESTAMP(0) WITH TIME ZONE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE shared_notification_delivery');
        $this->addSql('ALTER TABLE community_pending_guild_activity DROP window_id');
        $this->addSql('ALTER TABLE community_pending_guild_activity DROP opened_at');
    }
}
