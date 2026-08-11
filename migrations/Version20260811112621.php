<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `progression_snapshot` : l'état d'un joueur, prêt à être lu.
 *
 * **Un cache, jamais une vérité.** Tout ce que la table porte se redéduit du ledger et de la
 * courbe de niveaux ; la commande de reconstruction (#20) la réécrira à l'identique. C'est
 * aussi pour ça qu'elle n'a aucune contrainte que le ledger n'ait déjà : une ligne fausse se
 * répare en reprojetant, pas en la corrigeant.
 *
 * `user_id` **est** la clé primaire, sans identifiant propre : une ligne par compte, et
 * l'unicité vient de la structure au lieu de reposer sur un index qu'on pourrait oublier.
 * C'est cette ligne que la complétion verrouille en `PESSIMISTIC_WRITE` — un verrou par
 * joueur, donc deux comptes ne s'attendent jamais.
 *
 * Pas de clé étrangère vers `"user"`, comme sur le ledger : `Identity` est un autre module,
 * et la frontière vaut pour les tables autant que pour les classes.
 *
 * `xp_to_next_level` est la seule colonne nullable, et le `NULL` veut dire « niveau
 * maximum ». Zéro aurait voulu dire « le niveau suivant est atteint », ce qui est le
 * contraire — un plafond ne se distingue pas d'un palier avec un entier seul.
 *
 * Le diff Doctrine proposait en plus les deux `ALTER TABLE xp_transaction` de
 * `Version20260811110723`, que la base de dev n'avait pas encore appliqués. Ils ont été
 * retirés : rejouer un `ADD COLUMN` déjà passé casse la migration sur toute base à jour.
 */
final class Version20260811112621 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Progression : progression_snapshot, projection verrouillable du ledger';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE progression_snapshot (user_id UUID NOT NULL, total_xp INT NOT NULL, level INT NOT NULL, xp_into_level INT NOT NULL, xp_to_next_level INT DEFAULT NULL, earned_skill_points INT NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (user_id))');
    }

    public function down(Schema $schema): void
    {
        // Sans perte : la table se reconstruit depuis le ledger.
        $this->addSql('DROP TABLE progression_snapshot');
    }
}
