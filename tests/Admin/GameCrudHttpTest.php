<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Domain\GameTitle;
use App\Identity\Domain\Role;
use App\Identity\Infrastructure\Doctrine\UserRepository;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;

/** Le CRUD de configuration passe par les formulaires EasyAdmin réellement servis. */
final class GameCrudHttpTest extends ApiTestCase
{
    public function testTitleCanBeCreatedUpdatedDeactivatedAndDeletedThroughAdmin(): void
    {
        $this->loginAdmin();
        $key = 'http_admin_title_'.bin2hex(random_bytes(4));
        $crawler = $this->client->request('GET', '/admin/title/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="GameTitle"]')->form();
        self::assertInstanceOf(Form::class, $form);
        $form->setValues([
            'GameTitle[key]' => $key,
            'GameTitle[active]' => '1',
            'GameTitle[sortOrder]' => (string) random_int(1_000_000, 2_000_000),
            'GameTitle[conditionType]' => 'session_count',
            'GameTitle[threshold]' => '7',
            'GameTitle[discipline]' => '',
            'GameTitle[translations][fr][name]' => 'Titre HTTP',
            'GameTitle[translations][fr][hint]' => 'Sept séances',
            'GameTitle[translations][en][name]' => 'HTTP title',
            'GameTitle[translations][en][hint]' => 'Seven sessions',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects();

        $title = self::getContainer()->get('doctrine')->getRepository(GameTitle::class)->findOneBy(['key' => $key]);
        self::assertInstanceOf(GameTitle::class, $title);
        self::assertTrue($title->isActive());

        $crawler = $this->client->request('GET', '/admin/title/'.$title->getId()->toRfc4122().'/edit');
        self::assertResponseIsSuccessful();
        $edit = $crawler->filter('form[name="GameTitle"]')->form();
        $active = $edit['GameTitle[active]'];
        self::assertInstanceOf(ChoiceFormField::class, $active);
        $active->untick();
        $this->client->submit($edit);
        self::assertResponseRedirects();
        self::getContainer()->get('doctrine')->getManager()->clear();
        $deactivated = self::getContainer()->get('doctrine')->getRepository(GameTitle::class)->findOneBy(['key' => $key]);
        self::assertInstanceOf(GameTitle::class, $deactivated);
        self::assertFalse($deactivated->isActive());

        $deletePage = $this->client->request('GET', '/admin/title/'.$deactivated->getId()->toRfc4122().'/edit');
        $token = $deletePage->filter('#action-confirmation-form input[name="token"]')->attr('value');
        self::assertIsString($token);
        $this->client->request('POST', '/admin/title/'.$deactivated->getId()->toRfc4122().'/delete', ['token' => $token]);
        self::assertResponseRedirects();
        self::getContainer()->get('doctrine')->getManager()->clear();
        self::assertNull(self::getContainer()->get('doctrine')->getRepository(GameTitle::class)->findOneBy(['key' => $key]));
    }

    private function loginAdmin(): void
    {
        $email = 'crud-http-admin@grrind.app';
        $this->openAccount($email);
        $users = self::getContainer()->get(UserRepository::class);
        $user = $users->ofEmail($email);
        self::assertNotNull($user);
        $user->grant(Role::Admin);
        $users->commit();

        $login = $this->client->request('GET', '/admin/login');
        $token = $login->filter('input[name="_csrf_token"]')->attr('value');
        self::assertIsString($token);
        $this->client->request('POST', '/admin/login', ['_username' => $email, '_password' => 'un-mot-de-passe-assez-long', '_csrf_token' => $token]);
    }
}
