<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initialise uniquement les objets existants à la moitié du prix d'achat, division entière
 * PostgreSQL. Les créations ultérieures partent de zéro et les deux prix restent indépendants.
 * Les snapshots/audits existants restent intacts : ItemCatalog sait lire leur ancien format.
 */
final class Version20260908170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initialise le prix de revente administrable des objets (#271).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_item ADD sell_price_coins INT NOT NULL DEFAULT 0 CHECK (sell_price_coins >= 0)');
        $this->addSql('UPDATE game_item SET sell_price_coins = price_coins / 2');
        $this->addSql('ALTER TABLE game_item ALTER sell_price_coins DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_item DROP sell_price_coins');
    }
}
