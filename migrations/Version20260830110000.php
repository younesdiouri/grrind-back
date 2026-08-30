<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `combat_battle` porte désormais sa récompense (#227) — même geste et même piège qu'au
 * `Version20260825130000` pour `progression_snapshot` : une colonne `NOT NULL` sans défaut
 * échouerait net sur une table déjà peuplée, donc un défaut transitoire — la forme vide de
 * `App\Shared\Application\BattleDrop::none()` — posé le temps de la migration puis retiré ;
 * le mapping n'en déclare aucun, pour ne pas faire diverger le prochain
 * `doctrine:migrations:diff`.
 *
 * **Contrairement au `0/0/0/0` de `progression_snapshot`, ce défaut n'est jamais corrigé
 * après coup, et c'est assumé (phase de dev, `CLAUDE.md`).** `combat_battle` n'est pas un
 * cache reconstructible depuis une autre source de vérité — aucune table `rewards_loot_roll`
 * n'existait avant le #227 pour ces lignes, il n'y a donc rien à rejouer. Sans joueur réel
 * à date, les lignes déjà écrites (#211-#226) n'ont jamais fait tomber de récompense ; leur
 * laisser une forme vide *dit le vrai* plutôt que d'inventer un tirage rétroactif.
 */
final class Version20260830110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Combat : la ligne d\'un combat porte sa récompense (#227)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE combat_battle ADD reward JSONB NOT NULL DEFAULT \'{"loot": [], "coins": {"gained": 0, "before": 0, "after": 0}}\'');
        $this->addSql('ALTER TABLE combat_battle ALTER COLUMN reward DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE combat_battle DROP reward');
    }
}
