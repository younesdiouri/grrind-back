<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `xp_transaction.created_at` devient `occurred_at` (#89).
 *
 * Un `RENAME`, pas un `DROP` + `ADD` : la colonne garde son type, son contenu et ses
 * lignes. C'est ce que le diff de Doctrine propose de travers à chaque fois, et c'est
 * pourquoi les migrations se relisent à la main.
 *
 * **Le renommage n'est pas cosmétique, il précède un changement de sens.** Tant que Grrind
 * tenait le chronomètre, l'instant de l'écriture et celui du sport étaient le même ; l'import
 * les sépare de plusieurs jours. C'est l'instant du **sport** qui range une écriture dans
 * une journée, et donc qui gouverne les rendements décroissants et le plafond quotidien.
 * Garder le nom `created_at` sur une colonne qui ne dit plus ça, c'était planter le piège
 * là où le prochain bug se cacherait.
 *
 * Les lignes existantes sont exactes sans être touchées : elles ont été écrites par le
 * chronomètre, où les deux instants coïncidaient.
 *
 * L'instant de l'écriture n'est pas perdu — l'identifiant est un UUID v7, il l'encode.
 */
final class Version20260813160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Progression : le ledger est daté par le sport, pas par son écriture';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE xp_transaction RENAME COLUMN created_at TO occurred_at');
        $this->addSql('ALTER INDEX idx_xp_transaction_user_created RENAME TO idx_xp_transaction_user_occurred');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER INDEX idx_xp_transaction_user_occurred RENAME TO idx_xp_transaction_user_created');
        $this->addSql('ALTER TABLE xp_transaction RENAME COLUMN occurred_at TO created_at');
    }
}
