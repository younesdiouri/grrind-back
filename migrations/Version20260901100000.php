<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #250 : le pointeur qui permet à `RefreshSessionHandler` de retrouver le successeur direct
 * d'un jeton présenté, pour juger si une rotation a simplement été perdue en vol plutôt que
 * volée. Voir le docblock de `App\Identity\Domain\RefreshToken`.
 *
 * Pas de contrainte de clé étrangère, comme `family_id` : c'est un pointeur applicatif à
 * l'intérieur de la même table, jamais une association qu'on charge en cascade.
 *
 * **Amputée à la main**, comme les migrations précédentes du dépôt : le diff proposait aussi
 * de supprimer l'index partiel `uniq_community_invite_code_live` et la contrainte composite
 * `fk_player_active_title_unlocked` — deux écritures que Doctrine ne sait pas relire dans le
 * mapping et croit donc en trop à chaque génération, sans rapport avec ce ticket (voir déjà
 * Version20260901090000).
 */
final class Version20260901100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Identity : pointeur successor_id sur identity_refresh_token (#250)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity_refresh_token ADD successor_id UUID DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity_refresh_token DROP successor_id');
    }
}
