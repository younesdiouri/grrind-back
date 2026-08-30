<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le module Rewards : le ledger de pièces (#225), même geste que `rewards_loot_roll` (#28)
 * — voir le docblock de `Version20260830090655`.
 *
 * **Amputée à la main**, comme toutes les migrations de ce dépôt. Le diff proposait en plus
 * de retextifier `uniq_community_risala_open_turn`, `uniq_community_invite_code_live`, et de
 * recréer la contrainte composite `fk_player_active_title_unlocked` avec son index. Rien de
 * tout ça ne vient de ce ticket ; les appliquer aurait touché deux garde-fous d'un autre
 * module sans qu'aucun test ne le voie ici.
 *
 * `user_id` et `source_id` sont des UUID nus, sans clé étrangère : `Rewards` ne connaît ni
 * `Identity` ni `Training` ni `Combat`, et Deptrac l'interdirait même si la base le
 * permettait — voir le docblock de `App\Rewards\Domain\CoinTransaction`.
 *
 * Un seul index, `(user_id, id)` : le solde est une somme, jamais une colonne, et c'est cet
 * index qui la garde une lecture d'index plutôt qu'un balayage complet — voir le docblock
 * de `App\Rewards\Infrastructure\Doctrine\CoinTransactionRepository::balanceOf()`.
 */
final class Version20260830093021 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rewards : le ledger de pièces, append-only';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE rewards_coin_transaction (id UUID NOT NULL, user_id UUID NOT NULL, amount INT NOT NULL, reason VARCHAR(16) NOT NULL, source_id UUID NOT NULL, occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_rewards_coin_transaction_user_id ON rewards_coin_transaction (user_id, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE rewards_coin_transaction');
    }
}
