<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * L'index de `GET /api/progression/history` (#19).
 *
 * L'historique se lit « les écritures de ce joueur, les plus récentes d'abord », et pagine
 * sur l'identifiant — UUID v7, donc triable, donc chronologique. `idx_xp_transaction_user_created`
 * ne sait pas servir ce tri : il ordonne par date, et PostgreSQL retomberait sur la clé
 * primaire en filtrant le compte, ce qui ferait payer à un joueur inactif tout ce que les
 * autres ont écrit depuis sa dernière séance.
 *
 * Deux index sur la même table, donc, et ils servent deux requêtes différentes : celui par
 * date porte les garde-fous quotidiens, lus à chaque complétion. La table est écrite
 * quelques fois par jour et par joueur — c'est le bon côté du compromis pour payer en
 * lecture ce qui ne coûte presque rien en écriture.
 *
 * **Ce que le diff proposait en plus a été retiré** : un `DROP` de
 * `fk_player_active_title_unlocked` et de son index. La contrainte est écrite à la main
 * (voir Version20260811142405) parce que Doctrine ne sait pas exprimer une clé étrangère
 * composée sans en faire une association ; il ne la voit donc pas dans le mapping et
 * propose de la supprimer à chaque diff.
 */
final class Version20260811151147 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Progression : index de pagination du ledger';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_xp_transaction_user_id ON xp_transaction (user_id, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_xp_transaction_user_id');
    }
}
