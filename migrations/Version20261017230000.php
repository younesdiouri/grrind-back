<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/** Phase de dev : retire les calques rejetés de la sélection, sans changer les faits de jeu. */
final class Version20261017230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bases pieds nus, garde commune, prise verticale et six objets portés en V2.';
    }

    public function up(Schema $schema): void
    {
        $source = file_get_contents(__DIR__.'/data/retro-v2.json');
        \assert(false !== $source);
        /** @var list<array{kind:string,key:string,version:string,name:string,active:bool,sortOrder:int,manifest:array<string,mixed>}> $packs */
        $packs = json_decode($source, true, flags: \JSON_THROW_ON_ERROR);
        $this->addSql("UPDATE game_artwork_pack SET active = FALSE WHERE version = 'v1' AND pack_key IN ('STRENGTH_V1', 'RUNNER_V1', 'CLIMBER_V1', 'SWIMMER_V1', 'HALTER_EGO', 'PURSUIT_GOGGLES', 'QUIET_STRENGTH_BELT', 'ALL_PURPOSE_FLASK', 'HEADACHE_CAP', 'GENIE_CHALK_BAG', 'MIDNIGHT_SWIM_CAP', 'ACADEMIC_FLIPPERS')");
        foreach ($packs as $pack) {
            $this->addSql('INSERT INTO game_artwork_pack (id, pack_key, kind, version, name, active, sort_order, manifest_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
                Uuid::v7()->toRfc4122(), $pack['key'], $pack['kind'], $pack['version'], $pack['name'],
                $pack['active'], $pack['sortOrder'], json_encode($pack['manifest'], \JSON_THROW_ON_ERROR),
            ]);
        }
        $this->addSql("UPDATE identity_user SET appearance = REPLACE(appearance, '_V1', '_V2') WHERE appearance IN ('STRENGTH_V1', 'RUNNER_V1', 'CLIMBER_V1', 'SWIMMER_V1')");
        $this->addSql("ALTER TABLE identity_user ALTER appearance SET DEFAULT 'STRENGTH_V2'");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les profils utilisent les nouvelles bases ; les anciens packs restent archivés pour les replays.');
    }
}
