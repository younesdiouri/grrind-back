<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le bonus cardiaque (#164) : deux paliers de FC moyenne **brute**, en pourcentage du socle.
 *
 * Les valeurs ne sont calibrées sur rien — on n'a aucun joueur — et c'est assumé : elles
 * vivent dans le réglage administrable pour être corrigées sans déploiement dès que les
 * premières données réelles arrivent. Le brouillon et le snapshot publié reçoivent les mêmes
 * paliers, et la nouvelle empreinte isole l'historique déjà crédité sous l'ancienne.
 */
final class Version20261003120000 extends AbstractMigration
{
    private const array TIERS = [['from_bpm' => 130, 'bonus_percent' => 10], ['from_bpm' => 150, 'bonus_percent' => 20]];

    public function getDescription(): string
    {
        return 'Bonus cardiaque par paliers de FC moyenne (#164).';
    }

    public function up(Schema $schema): void
    {
        require_once __DIR__.'/AlamRulesetVersion.php';
        $tiers = json_encode(self::TIERS, \JSON_THROW_ON_ERROR);
        $this->addSql("UPDATE game_settings SET xp = jsonb_set(xp::jsonb, '{heart_rate_bonus}', ?::jsonb)::json WHERE id = 1", [$tiers]);
        $stored = $this->connection->fetchOne('SELECT snapshot FROM game_ruleset WHERE id = 1');
        if (!\is_string($stored)) {
            return;
        }
        /** @var array{xp: array<string, mixed>} $snapshot */
        $snapshot = json_decode($stored, true, 512, \JSON_THROW_ON_ERROR);
        $snapshot['xp']['heart_rate_bonus'] = self::TIERS;
        $this->addSql('UPDATE game_ruleset SET snapshot = ?, version = ?, revision = revision + 1 WHERE id = 1', [json_encode($snapshot, \JSON_THROW_ON_ERROR), AlamRulesetVersion::of($snapshot)]);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Des séances ont pu être créditées sous la nouvelle empreinte.');
    }
}
