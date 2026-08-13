<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `MANUAL_TIMER` et `STRAVA` disparaissent de {@see \App\Shared\Domain\Activity\WorkoutSource} (#87).
 *
 * **Aucun changement de schéma** : `source` est un `VARCHAR` que Doctrine hydrate en enum,
 * il n'y a ni type PostgreSQL ni contrainte `CHECK` à faire évoluer. Ce qui doit changer,
 * c'est la donnée : une ligne portant une valeur que l'enum ne connaît plus fait lever
 * l'hydratation, et le workout devient illisible sans être invalide pour autant. Le
 * problème ne se manifesterait qu'à la lecture, loin d'ici.
 *
 * Les lignes concernées sont supprimées plutôt que réécrites. Les convertir demanderait de
 * choisir un agrégateur à leur place — dire qu'une séance chronométrée à la main vient
 * d'Apple Health serait faux, et ce mensonge-là se propagerait au `trust`, qui passerait de
 * `DECLARED` à `PROVIDER_VERIFIED` : une séance déclarée deviendrait une séance vérifiée
 * par un fournisseur qui ne l'a jamais vue. Le ledger d'XP, lui, est append-only et n'est
 * pas touché : ce qui a été crédité reste crédité.
 *
 * Le `DELETE` est sans conséquence ici et ne le sera plus jamais — aucun compte en
 * production. C'est exactement la fenêtre où ce virage coûte une migration plutôt qu'une
 * reprise de données.
 */
final class Version20260813140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shared : les sources du chronomètre et de Strava quittent le contrat';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DELETE FROM workout WHERE source NOT IN ('APPLE_HEALTH', 'HEALTH_CONNECT')");
    }

    /**
     * Rien à défaire. Réélargir l'enum ne ressusciterait pas les lignes, et une migration
     * descendante qui n'a rien à rendre doit le dire plutôt que de faire semblant.
     */
    public function down(Schema $schema): void
    {
    }
}
