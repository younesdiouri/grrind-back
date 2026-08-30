<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Correction du #30 : `idx_rewards_coin_transaction_user_id`, posé au #225 sur
 * `(user_id, id)` pour que le solde reste une lecture d'index, ne sert pas le tri de
 * `GET /api/inventory/coins` — `(occurredAt, id)`, l'ordre du **fait**, pas celui de
 * l'écriture. Une pièce créditée par un workout vieux de dix jours doit se ranger dix jours
 * en arrière, à côté de la ligne d'XP du même workout ; un tri par `id` aurait laissé
 * `GET /api/progression/history` et `GET /api/inventory/coins` montrer le même import dans
 * deux ordres différents.
 *
 * Un `DROP` puis un `CREATE`, jamais un `ALTER` — même geste et même raison que
 * `Version20260829160000` sur `idx_combat_battle_player` : PostgreSQL n'a pas de syntaxe
 * pour étendre un index existant, et le diff Doctrine généré à l'aveugle aurait proposé la
 * même paire — relue ici plutôt que produite en confiance.
 *
 * `balanceOf()` n'est pas concerné : c'est une somme, `user_id` en tête de l'index lui
 * suffit toujours, qu'il soit suivi de une ou deux colonnes.
 */
final class Version20260830120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rewards : idx_rewards_coin_transaction_user_id devient un index de pagination (user_id, occurred_at DESC, id DESC)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_rewards_coin_transaction_user_id');
        $this->addSql('CREATE INDEX idx_rewards_coin_transaction_user_id ON rewards_coin_transaction (user_id, occurred_at DESC, id DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_rewards_coin_transaction_user_id');
        $this->addSql('CREATE INDEX idx_rewards_coin_transaction_user_id ON rewards_coin_transaction (user_id, id)');
    }
}
