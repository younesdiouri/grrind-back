<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le module Rewards : la ligne d'audit d'un tirage de loot (#28).
 *
 * **Amputée à la main**, comme toutes les migrations de ce dépôt — voir le docblock de
 * `Version20260829091957` pour le même geste sur `Combat`. Le diff proposait en plus de
 * retextifier l'index partiel `uniq_community_risala_open_turn`, de recréer la contrainte
 * composite `fk_player_active_title_unlocked` avec son index. Rien de tout ça ne vient de
 * ce ticket ; les appliquer aurait cassé deux garde-fous d'un autre module sans qu'aucun
 * test ne le voie ici.
 *
 * `user_id` et `cause_id` sont des UUID nus, sans clé étrangère : `Rewards` ne connaît ni
 * `Identity` ni `Training` ni `Combat`, et Deptrac l'interdirait même si la base le
 * permettait — voir le docblock de `App\Rewards\Domain\LootRoll`.
 *
 * `roll` et `result` sont `JSONB` plutôt que `JSON` : c'est ce que rend
 * `Doctrine\DBAL\Types\Types::JSONB`, un type natif de DBAL, sans type custom à déclarer
 * dans `doctrine.yaml` — même choix que `combat_battle`.
 */
final class Version20260830090655 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rewards : la ligne d\'audit d\'un tirage de loot';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE rewards_loot_roll (id UUID NOT NULL, user_id UUID NOT NULL, origin VARCHAR(16) NOT NULL, cause_id UUID NOT NULL, seed VARCHAR(64) NOT NULL, table_key VARCHAR(64) NOT NULL, table_version INT NOT NULL, effective_loot_luck_percent INT NOT NULL, roll JSONB NOT NULL, result JSONB NOT NULL, rolled_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');

        // L'historique d'un joueur part toujours de lui, du tirage le plus récent — même
        // raison que `idx_combat_battle_player` : sans cet index, le lister ferait un
        // parcours séquentiel de tous les tirages de tout le monde.
        $this->addSql('CREATE INDEX idx_rewards_loot_roll_user ON rewards_loot_roll (user_id, rolled_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE rewards_loot_roll');
    }
}
