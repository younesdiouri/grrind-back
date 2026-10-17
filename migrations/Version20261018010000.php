<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

final class Version20261018010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Corriger les parties cachées des lunettes ; conserver les anciennes versions pour les replays.';
    }

    public function up(Schema $schema): void
    {
        $source = file_get_contents(__DIR__.'/data/goggles-v4.json');
        \assert(false !== $source);
        /** @var array{kind:string,key:string,version:string,name:string,active:bool,sortOrder:int,manifest:array<string,mixed>} $pack */
        $pack = json_decode($source, true, flags: \JSON_THROW_ON_ERROR);
        $this->addSql("UPDATE game_artwork_pack SET active = FALSE WHERE kind = 'EQUIPMENT' AND pack_key = 'PURSUIT_GOGGLES'");
        $this->addSql('INSERT INTO game_artwork_pack (id, pack_key, kind, version, name, active, sort_order, manifest_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
            Uuid::v7()->toRfc4122(), $pack['key'], $pack['kind'], $pack['version'], $pack['name'],
            $pack['active'], $pack['sortOrder'], json_encode($pack['manifest'], \JSON_THROW_ON_ERROR),
        ]);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les nouveaux combats référencent les lunettes V4 ; les anciens packs restent disponibles.');
    }
}
