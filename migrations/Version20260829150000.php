<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * L'esquive (#218) : `Fighter` gagne un cinquième champ, `dodgePermille`, porté par les deux
 * snapshots JSONB de `combat_battle` (`player_snapshot.fighter` et
 * `enemy_snapshot.fighter`).
 *
 * **On vide la table plutôt que de migrer ses lignes.** Grrind n'est pas déployé et n'a pas
 * de joueur réel — voir `CLAUDE.md`, « Où en est le produit » — donc il n'existe aucune
 * donnée à perdre, seulement des lignes de test jetables. Les lignes déjà écrites n'ont pas
 * de clé `dodgePermille` dans leur snapshot ; `FighterResource` lirait une clé absente en
 * tentant de les rejouer. Écrire du code de compatibilité — une valeur par défaut pour un
 * champ qui n'existait pas au moment de l'écriture — coûterait plus qu'un `TRUNCATE`, pour
 * protéger des combats que personne n'a joués pour de vrai.
 *
 * Cette décision saute au premier joueur réel : voir `CLAUDE.md`.
 */
final class Version20260829150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Combat : dodgePermille dans les snapshots — table vidée, phase de dev sans joueur';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('TRUNCATE combat_battle');
    }

    public function down(Schema $schema): void
    {
        // Rien à défaire : `up()` ne change pas le schéma, seulement le contenu — et un
        // contenu vidé ne se restaure pas depuis une migration.
    }
}
