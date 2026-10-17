<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** La jambe arrière est ouverte vers l’extérieur : sa palme doit aussi être reflétée. */
final class Version20261017220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reflète la palme arrière et son pivot en conservant le calibrage du pied avant.';
    }

    public function up(Schema $schema): void
    {
        $source = $this->connection->fetchOne("SELECT manifest_json FROM game_artwork_pack WHERE kind = 'EQUIPMENT' AND pack_key = 'ACADEMIC_FLIPPERS' AND version = 'v1'");
        if (!\is_string($source)) {
            return;
        }
        /** @var array{layers:list<array{attachment:string,sourceUrls:array{back:string,front:string},pivot:array{x:float,y:float}}>} $manifest */
        $manifest = json_decode($source, true, flags: \JSON_THROW_ON_ERROR);
        foreach ($manifest['layers'] as &$layer) {
            if ('footRear' !== $layer['attachment'] || '/artwork/items/flipper/v1/back.png' !== $layer['sourceUrls']['back']) {
                continue;
            }
            $layer['sourceUrls'] = ['back' => $layer['sourceUrls']['front'], 'front' => $layer['sourceUrls']['back']];
            $layer['pivot']['x'] = 1 - $layer['pivot']['x'];
        }
        unset($layer);
        $this->addSql("UPDATE game_artwork_pack SET manifest_json = ? WHERE kind = 'EQUIPMENT' AND pack_key = 'ACADEMIC_FLIPPERS' AND version = 'v1'", [json_encode($manifest, \JSON_THROW_ON_ERROR)]);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Le calibrage corrigé suit l’orientation des deux pieds.');
    }
}
