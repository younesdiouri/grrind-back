<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La discipline et la durée que chaque écriture d'XP valorise.
 *
 * Elles rendent le ledger auto-suffisant pour les garde-fous quotidiens : les rendements
 * décroissants ont besoin du temps cumulé de la journée, le plafond d'XP est par
 * discipline, et `Progression` doit répondre aux deux sans franchir la frontière de
 * `Training`. `SUM(duration_seconds)` et `SUM(amount) … WHERE discipline = ?` sur l'index
 * `(user_id, created_at)` suffisent.
 *
 * **`NOT NULL` sans valeur par défaut, et c'est voulu.** PROGRESS.md note que c'est
 * habituellement le piège d'un diff Doctrine sur une table peuplée. Ici c'est la garde :
 * il n'existe aucune valeur honnête à écrire dans une ligne existante — on ne devine pas la
 * discipline d'une XP déjà accordée — donc l'`ALTER` doit échouer si la table n'est pas
 * vide. Elle l'est, et le restera jusqu'au Lot 4 : rien n'écrit encore au ledger, le
 * premier producteur est la transaction de complétion (#21).
 *
 * `duration_seconds` est **signée** : l'annulation d'une séance porte une durée négative,
 * ce qui laisse les deux compteurs de la journée se solder par simple somme.
 */
final class Version20260811110723 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Progression : discipline et durée sur le ledger, pour les garde-fous quotidiens';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE xp_transaction ADD discipline VARCHAR(32) NOT NULL');
        $this->addSql('ALTER TABLE xp_transaction ADD duration_seconds INT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Destructive, comme tout retour arrière qui supprime une colonne : ce qui y était
        // ne se retrouve nulle part ailleurs.
        $this->addSql('ALTER TABLE xp_transaction DROP discipline');
        $this->addSql('ALTER TABLE xp_transaction DROP duration_seconds');
    }
}
