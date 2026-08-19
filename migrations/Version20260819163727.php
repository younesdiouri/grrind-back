<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les tickets Expo acceptés, en attente de leur reçu de livraison (#131) — voir le docblock
 * de `App\Shared\Domain\Notification\PendingPushReceipt`.
 *
 * **Amputée à la main**, même geste que les migrations précédentes : le diff propose aussi de
 * supprimer l'index partiel `uniq_community_invite_code_live` et la contrainte composite
 * `fk_player_active_title_unlocked` (avec son index `IDX_2D369A0FA76ED395A9F87BD`) — trois
 * écritures que Doctrine ne sait pas relire dans le mapping et croit donc en trop à chaque
 * génération, sans rapport avec ce ticket.
 *
 * `ticket_id`, `push_token` et `created_at` en `NOT NULL` sans défaut : la table ne contient
 * que des tickets en attente de reçu, jamais d'historique à préserver — une ligne déjà en
 * base au moment du déploiement n'a rien à migrer, la fonctionnalité n'existait pas avant.
 */
final class Version20260819163727 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shared : tickets Expo en attente de reçu (#131)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE shared_pending_push_receipt (id UUID NOT NULL, ticket_id VARCHAR(255) NOT NULL, push_token VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_shared_pending_push_receipt_ticket ON shared_pending_push_receipt (ticket_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE shared_pending_push_receipt');
    }
}
