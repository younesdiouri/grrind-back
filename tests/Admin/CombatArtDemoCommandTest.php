<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Domain\GameEnemy;
use App\Admin\Domain\GameRuleset;
use App\Admin\Infrastructure\GameRulesetPublisher;
use App\Admin\UI\Console\CombatArtDemoCommand;
use App\Community\Infrastructure\Doctrine\GuildMembershipRepository;
use App\Identity\Infrastructure\Doctrine\UserRepository;
use App\Rewards\Application\EquipItemHandler;
use App\Rewards\Domain\EquipmentSlot;
use App\Rewards\Infrastructure\Doctrine\InventoryItemRepository;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;

final class CombatArtDemoCommandTest extends ApiTestCase
{
    public function testTheExplicitLocalSetupIsIdempotentAndPreservesWornGearAndGameplay(): void
    {
        $owner = $this->openAccount('owner@grrind.app');
        $member = $this->openAccount('member@grrind.app');
        $outside = $this->openAccount('outside@grrind.app');
        $guild = self::decode($this->post('/api/guilds', ['name' => 'Atelier'], $owner->headers));
        self::assertIsString($guild['id']);
        $invite = self::decode($this->post('/api/guilds/'.$guild['id'].'/invite-code', [], $owner->headers));
        $this->post('/api/guilds/join', ['code' => $invite['code']], $member->headers);
        $container = self::getContainer();
        $manager = $container->get(EntityManagerInterface::class);
        $inventory = $container->get(InventoryItemRepository::class);
        $inventory->grant($owner->id, 'CROWN_OF_THE_TIRELESS', null, new DateTimeImmutable());
        $this->send('PUT', '/api/inventory/equipment/HEAD', ['itemKey' => 'CROWN_OF_THE_TIRELESS'], $owner->headers);
        $container = self::getContainer();
        $manager = $container->get(EntityManagerInterface::class);
        $inventory = $container->get(InventoryItemRepository::class);
        $enemy = $manager->getRepository(GameEnemy::class)->findOneBy(['key' => 'SAND_JACKAL']);
        self::assertInstanceOf(GameEnemy::class, $enemy);
        $hp = $enemy->getHp();
        $ruleset = $manager->find(GameRuleset::class, 1);
        self::assertInstanceOf(GameRuleset::class, $ruleset);
        $version = $ruleset->version();
        $filesystem = new Filesystem();
        $source = sys_get_temp_dir().'/combat-art-'.Uuid::v7()->toRfc4122();
        $filesystem->mkdir([$source.'/pebble', $source.'/headband']);
        foreach (['pebble/idle', 'pebble/attack', 'pebble/hit', 'headband/icon'] as $file) {
            $filesystem->copy('/app/public/artwork/avatars/strength/v1/back/idle.png', $source.'/'.$file.'.png');
        }
        // ApiTestCase conserve game_* entre tests : la démo ne doit pas publier son atelier dans les autres fixtures.
        $connection = $manager->getConnection();
        $connection->beginTransaction();
        try {
            $command = new CommandTester($this->command('dev'));
            $args = ['email' => 'owner@grrind.app', '--art-dir' => $source];
            self::assertSame(0, $command->execute($args));
            $revision = $ruleset->revision();
            self::assertSame(0, $command->execute($args));
            self::assertSame($revision, $ruleset->revision());
            self::assertSame($version, $ruleset->version());
            self::assertSame($hp, $enemy->getHp());
            self::assertSame('Cailloux dans la Chaussure', $enemy->getTranslations()['fr']['name']);
            self::assertSame('CROWN_OF_THE_TIRELESS', $inventory->equippedIn($owner->id, EquipmentSlot::Head)?->itemKey());
            self::assertSame('TEMPERED_HEADBAND', $inventory->equippedIn($member->id, EquipmentSlot::Head)?->itemKey());
            self::assertSame(1, $inventory->ofPlayerAndItem($member->id, 'TEMPERED_HEADBAND')?->quantity());
            self::assertNull($inventory->equippedIn($outside->id, EquipmentSlot::Head));
        } finally {
            $connection->rollBack();
            $manager->clear();
            $container->get(GameRulesetPublisher::class)->invalidateAfterCommit();
            $filesystem->remove($source);
        }
    }

    public function testItCannotRunInProduction(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('réservée');
        new CommandTester($this->command('prod'))->execute(['email' => 'absent@example.test']);
    }

    private function command(string $environment): CombatArtDemoCommand
    {
        $container = self::getContainer();

        return new CombatArtDemoCommand(
            $container->get(EntityManagerInterface::class),
            $container->get(GameRulesetPublisher::class),
            $container->get(UserRepository::class),
            $container->get(GuildMembershipRepository::class),
            $container->get(InventoryItemRepository::class),
            $container->get(EquipItemHandler::class),
            $environment,
            '/app/var/game-images',
        );
    }
}
