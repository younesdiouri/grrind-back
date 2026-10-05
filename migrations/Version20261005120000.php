<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * L'apparence du joueur (#289) : un modèle de héros du catalogue `Appearance`, `MURID` pour
 * tous les comptes existants.
 *
 * Les éditions d'Ālam déjà résolues ne sont pas réécrites : leurs participants n'ont pas
 * d'`appearance` ni leurs rencontres d'`imageUrls`. Décision de phase de dev, base jetable.
 */
final class Version20261005120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Apparence du joueur (#289).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE identity_user ADD appearance VARCHAR(32) DEFAULT 'MURID' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity_user DROP appearance');
    }
}
