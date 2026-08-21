<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #149 : le nom `shared_notification_delivery` mentait — la ligne est une réservation
 * d'envoi écrite avant l'appel réseau (#134), jamais une preuve que le push est parti.
 * Voir le docblock d'{@see \App\Shared\Domain\Notification\NotificationAttempt}.
 *
 * **`RENAME`, pas `DROP` + `CREATE`.** Le diff généré aurait proposé de supprimer la
 * table et d'en recréer une identique sous le nouveau nom — perdant au passage les lignes
 * en vol, dont une résume exactement l'état d'une fenêtre d'annonce que le #134 protège.
 * Aucune colonne ne change, seule l'identité de la table et de sa contrainte d'unicité.
 */
final class Version20260821090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shared : shared_notification_delivery devient shared_notification_attempt, le nom disait « livré » là où il veut dire « réservé » (#149)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shared_notification_delivery RENAME TO shared_notification_attempt');
        $this->addSql('ALTER INDEX uniq_shared_notification_delivery RENAME TO uniq_shared_notification_attempt');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER INDEX uniq_shared_notification_attempt RENAME TO uniq_shared_notification_delivery');
        $this->addSql('ALTER TABLE shared_notification_attempt RENAME TO shared_notification_delivery');
    }
}
