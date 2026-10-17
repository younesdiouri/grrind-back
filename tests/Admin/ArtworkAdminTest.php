<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Domain\GameArtworkPack;
use App\Admin\UI\EasyAdmin\ArtworkPackCrudController;
use App\Admin\UI\EasyAdmin\DashboardController;
use App\Identity\Domain\Role;
use App\Identity\Infrastructure\Doctrine\UserRepository;
use App\Tests\Support\ApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ArtworkAdminTest extends ApiTestCase
{
    public function testOverviewAndImageUploadRequireAnAdminSessionAndCsrf(): void
    {
        $this->client->request('GET', '/admin/artwork');
        self::assertResponseRedirects('/admin/login');
        $this->admin();
        $crawler = $this->client->request('GET', '/admin/artwork');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#art-catalog');
        self::assertSelectorExists('form#art-upload');
        self::assertSelectorExists('dialog#art-inspector');
        self::assertSelectorExists('#art-inspector-body');
        $this->client->request('POST', '/admin/artwork/upload', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', '/admin/artwork/upload', ['_token' => $token]);
        self::assertResponseStatusCodeSame(422);
        $source = \dirname(__DIR__, 2).'/public/artwork/avatars/strength/v1/thumb/back/idle.png';
        $temporary = tempnam(sys_get_temp_dir(), 'grrind-art-test');
        self::assertIsString($temporary);
        copy($source, $temporary);
        $target = self::getContainer()->getParameter('kernel.project_dir').'/var/game-images/'.hash_file('sha1', $source).'.png';
        $alreadyPresent = is_file($target);
        try {
            $this->client->request('POST', '/admin/artwork/upload', ['_token' => $token], [
                'image' => new UploadedFile($temporary, 'avatar.png', 'image/png', test: true),
            ]);
            self::assertResponseStatusCodeSame(201);
            self::assertFileExists($target);
            self::assertSame('/game-images/'.basename($target), self::decode($this->client->getResponse())['path']);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
            if (!$alreadyPresent && is_file($target)) {
                unlink($target);
            }
        }
    }

    public function testAnAdminCanEditArtworkWithoutPublishingGameplayAndInvalidJsonCannotSave(): void
    {
        $this->admin();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $pack = $manager->getRepository(GameArtworkPack::class)->findOneBy(['key' => 'RUNNER_V1']);
        self::assertInstanceOf(GameArtworkPack::class, $pack);
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $before = $connection->fetchAssociative('SELECT revision, version FROM game_ruleset WHERE id = 1');
        $oldName = $pack->getName();
        $url = self::getContainer()->get(AdminUrlGenerator::class)->setDashboard(DashboardController::class)
            ->setController(ArtworkPackCrudController::class)->setAction(Action::EDIT)->setEntityId($pack->getId())->generateUrl();
        try {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            $form = $crawler->filter('form[name="GameArtworkPack"]')->form();
            $this->client->submit($form, ['GameArtworkPack[name]' => 'Course · essai']);
            self::assertResponseRedirects();
            self::assertSame('Course · essai', $connection->fetchOne("SELECT name FROM game_artwork_pack WHERE pack_key = 'RUNNER_V1'"));
            self::assertSame($before, $connection->fetchAssociative('SELECT revision, version FROM game_ruleset WHERE id = 1'));
            $crawler = $this->client->request('GET', $url);
            $form = $crawler->filter('form[name="GameArtworkPack"]')->form();
            $this->client->submit($form, ['GameArtworkPack[manifestJson]' => '{invalid']);
            self::assertResponseStatusCodeSame(422);
            $stored = $connection->fetchOne("SELECT manifest_json FROM game_artwork_pack WHERE pack_key = 'RUNNER_V1'");
            self::assertIsString($stored);
            self::assertNotNull(json_decode($stored, true));
        } finally {
            $connection->executeStatement("UPDATE game_artwork_pack SET name = ? WHERE pack_key = 'RUNNER_V1'", [$oldName]);
        }
    }

    private function admin(): void
    {
        $this->openAccount('art-admin@grrind.app');
        $users = self::getContainer()->get(UserRepository::class);
        $user = $users->ofEmail('art-admin@grrind.app');
        self::assertNotNull($user);
        $user->grant(Role::Admin);
        $users->commit();
        $this->client->loginUser($user, 'admin');
    }
}
