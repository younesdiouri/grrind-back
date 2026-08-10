<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Une seule séance active à la fois, garantie par la base.
 *
 * L'index unique **partiel** est le seul moyen d'exprimer « au plus une ligne ACTIVE
 * par joueur » sans contraindre les séances closes, qui sont légitimement nombreuses.
 * PostgreSQL le sait faire ; c'est aussi pour ça que l'invariant tient ici plutôt que
 * dans une vérification applicative, laquelle laisse toujours passer deux requêtes
 * simultanées entre son SELECT et son INSERT.
 *
 * Le prédicat est écrit ici tel qu'on le lit ; PostgreSQL le stocke normalisé
 * (`(status)::text = 'ACTIVE'::text`), et c'est cette forme-là que porte le mapping de
 * l'entité, sans quoi chaque `migrations:diff` reproposerait le même index.
 *
 * La création n'est pas `CONCURRENTLY` : la table est vide ou quasi, et un index
 * concurrent ne peut pas s'exécuter dans la transaction d'une migration. Le jour où
 * elle sera peuplée, ce sera à revoir — mais ce jour-là l'index existera déjà.
 */
final class Version20260810161500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Training : une seule séance active par joueur (index unique partiel)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE UNIQUE INDEX uniq_training_session_active ON training_session (user_id) WHERE (status = 'ACTIVE')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_training_session_active');
    }
}
