<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

final class Version20261018000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rétablir sept stances distinctes et leurs raccords, sans réinitialiser les profils ni les replays.';
    }

    public function up(Schema $schema): void
    {
        $source = file_get_contents(__DIR__.'/data/retro-v3.json');
        \assert(false !== $source);
        /** @var list<array{kind:string,key:string,version:string,name:string,active:bool,sortOrder:int,manifest:array<string,mixed>}> $packs */
        $packs = json_decode($source, true, flags: \JSON_THROW_ON_ERROR);
        $this->addSql("UPDATE game_artwork_pack SET active = FALSE WHERE version = 'v2' AND pack_key IN ('STRENGTH_V2', 'RUNNER_V2', 'CLIMBER_V2', 'SWIMMER_V2', 'HALTER_EGO', 'PURSUIT_GOGGLES', 'HEADACHE_CAP', 'MIDNIGHT_SWIM_CAP', 'ACADEMIC_FLIPPERS', 'TRAVELERS_CLOAK')");
        foreach ($packs as $pack) {
            $this->addSql('INSERT INTO game_artwork_pack (id, pack_key, kind, version, name, active, sort_order, manifest_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
                Uuid::v7()->toRfc4122(), $pack['key'], $pack['kind'], $pack['version'], $pack['name'],
                $pack['active'], $pack['sortOrder'], json_encode($pack['manifest'], \JSON_THROW_ON_ERROR),
            ]);
        }
        $this->addSql("UPDATE identity_user SET appearance = REPLACE(appearance, '_V2', '_V3') WHERE appearance IN ('STRENGTH_V2', 'RUNNER_V2', 'CLIMBER_V2', 'SWIMMER_V2')");
        $this->addSql("ALTER TABLE identity_user ALTER appearance SET DEFAULT 'STRENGTH_V3'");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les profils utilisent les nouvelles poses ; les versions antérieures restent disponibles aux replays.');
    }
}
