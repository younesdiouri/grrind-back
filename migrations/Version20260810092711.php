<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les clés d'idempotence des écritures métier.
 *
 * L'index unique (user_id, idempotency_key) n'est pas qu'une garantie de cohérence :
 * c'est lui que vise le ON CONFLICT de la réservation, et sans lui deux requêtes
 * concurrentes s'exécuteraient toutes les deux. Scopé au joueur, parce qu'une clé
 * interceptée ne doit jamais rendre la réponse d'un autre compte.
 *
 * L'index sur expires_at sert la purge de fond, ticketée au Lot 9. Pas de clé
 * étrangère vers identity_user : Shared ne connaît aucun module.
 */
final class Version20260810092711 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shared : table shared_idempotency_key';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE shared_idempotency_key (id UUID NOT NULL, user_id UUID NOT NULL, idempotency_key VARCHAR(255) NOT NULL, request_fingerprint VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, response_status INT DEFAULT NULL, response_headers JSON DEFAULT NULL, response_body TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_shared_idempotency_expires ON shared_idempotency_key (expires_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_shared_idempotency_user_key ON shared_idempotency_key (user_id, idempotency_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE shared_idempotency_key');
    }
}
