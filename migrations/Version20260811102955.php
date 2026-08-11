<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le ledger d'XP : `xp_transaction` et le détail de chaque calcul dans
 * `xp_transaction_line`.
 *
 * Deux tables et pas une colonne JSON : la source et la contribution d'une ligne sont des
 * colonnes typées, que PostgreSQL refuse de laisser dériver, et « combien d'XP ce joueur
 * doit-il à son streak » devient un `GROUP BY`.
 *
 * `uniq_xp_transaction_source_reason` est ce qui rend le ledger idempotent. Pas un
 * `SELECT` préalable : entre le contrôle et l'écriture, deux complétions rejouées par un
 * client mobile passent toutes les deux. Le couple (source, raison) autorise exactement ce
 * qu'il faut — une séance se crédite une fois, s'invalide une fois.
 *
 * `ON DELETE RESTRICT` sur la jointure plutôt que le `CASCADE` habituel : dans une table
 * append-only, la suppression en cascade est le contraire de ce qu'on veut. Rien ne doit
 * pouvoir emporter des lignes de détail sans que ça se voie.
 *
 * Pas de clé étrangère vers `training_session` ni vers `"user"` : `Progression` est un
 * autre module, et la frontière vaut pour les tables autant que pour les classes.
 *
 * `position` est un mot-clé PostgreSQL non réservé — utilisable tel quel comme nom de
 * colonne, ce que la montée de cette migration vérifie.
 */
final class Version20260811102955 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Progression : ledger append-only xp_transaction et son détail de calcul';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE xp_transaction (id UUID NOT NULL, user_id UUID NOT NULL, amount INT NOT NULL, reason VARCHAR(32) NOT NULL, source_id UUID NOT NULL, ruleset_version VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_xp_transaction_user_created ON xp_transaction (user_id, created_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_xp_transaction_source_reason ON xp_transaction (source_id, reason)');
        $this->addSql('CREATE TABLE xp_transaction_line (id UUID NOT NULL, position INT NOT NULL, source VARCHAR(32) NOT NULL, amount INT NOT NULL, transaction_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_xp_transaction_line_transaction ON xp_transaction_line (transaction_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_xp_transaction_line_position ON xp_transaction_line (transaction_id, position)');
        $this->addSql('ALTER TABLE xp_transaction_line ADD CONSTRAINT FK_3176EFCA2FC0CB0F FOREIGN KEY (transaction_id) REFERENCES xp_transaction (id) ON DELETE RESTRICT NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE xp_transaction_line DROP CONSTRAINT FK_3176EFCA2FC0CB0F');
        $this->addSql('DROP TABLE xp_transaction_line');
        $this->addSql('DROP TABLE xp_transaction');
    }
}
