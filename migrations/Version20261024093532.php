<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Les comptes existants résolvent NULL vers le défaut Unity V1 ; aucun historique n'est réécrit (#308). */
final class Version20261024093532 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la personnalisation cosmétique Unity au compte (#308).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity_user ADD avatar JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity_user DROP avatar');
    }
}
