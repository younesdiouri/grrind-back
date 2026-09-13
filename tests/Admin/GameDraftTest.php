<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Infrastructure\GameDraft;
use Doctrine\DBAL\Connection;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GameDraftTest extends KernelTestCase
{
    public function testAStaleRevisionCannotOverwriteTheDraftAndRollbackPreservesIt(): void
    {
        $draft = self::getContainer()->get(GameDraft::class);
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(GameDraft::class, $draft);
        self::assertInstanceOf(Connection::class, $connection);
        $before = $draft->revision();
        $published = $connection->fetchOne('SELECT snapshot FROM game_ruleset WHERE id = 1');
        $connection->beginTransaction();
        try {
            $draft->lock($before);
            $draft->advance();
            self::assertSame($before + 1, $draft->revision());
            try {
                $draft->lock($before);
                self::fail('Une édition périmée ne doit pas écraser le brouillon.');
            } catch (LogicException $exception) {
                self::assertStringContainsString('Actualisez', $exception->getMessage());
            }
            self::assertSame($published, $connection->fetchOne('SELECT snapshot FROM game_ruleset WHERE id = 1'));
        } finally {
            $connection->rollBack();
        }
        self::assertSame($before, $draft->revision());
    }
}
