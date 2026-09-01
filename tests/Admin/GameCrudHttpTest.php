<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Domain\GameEnemy;
use App\Admin\Domain\GameItem;
use App\Admin\Domain\GameSettings;
use App\Admin\Domain\GameTitle;
use App\Identity\Domain\Role;
use App\Identity\Infrastructure\Doctrine\UserRepository;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\FileFormField;
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

    public function testInactiveEnemyCanBeCreatedUpdatedAndDeletedThroughAdmin(): void
    {
        $this->loginAdmin('enemy-crud-admin@grrind.app');
        $key = 'HTTP_ENEMY_'.bin2hex(random_bytes(4));
        $crawler = $this->client->request('GET', '/admin/enemy/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="GameEnemy"]')->form();
        $active = $form['GameEnemy[active]'];
        self::assertInstanceOf(ChoiceFormField::class, $active);
        $active->untick();
        $form->setValues([
            'GameEnemy[key]' => $key,
            'GameEnemy[sortOrder]' => (string) random_int(2_000_001, 3_000_000),
            'GameEnemy[minimumLevel]' => '99',
            'GameEnemy[hp]' => '100',
            'GameEnemy[damage]' => '1',
            'GameEnemy[mitigationPermille]' => '0',
            'GameEnemy[extraTurnPermille]' => '0',
            'GameEnemy[dodgePermille]' => '0',
            'GameEnemy[translations][fr][name]' => 'Ennemi HTTP',
            'GameEnemy[translations][en][name]' => 'HTTP enemy',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects();

        $enemy = self::getContainer()->get('doctrine')->getRepository(GameEnemy::class)->findOneBy(['key' => $key]);
        self::assertInstanceOf(GameEnemy::class, $enemy);
        self::assertFalse($enemy->isActive());

        $crawler = $this->client->request('GET', '/admin/enemy/'.$enemy->getId()->toRfc4122().'/edit');
        $edit = $crawler->filter('form[name="GameEnemy"]')->form();
        $edit->setValues(['GameEnemy[hp]' => '101']);
        $this->client->submit($edit);
        self::assertResponseRedirects();
        self::getContainer()->get('doctrine')->getManager()->clear();
        $updated = self::getContainer()->get('doctrine')->getRepository(GameEnemy::class)->findOneBy(['key' => $key]);
        self::assertInstanceOf(GameEnemy::class, $updated);
        self::assertSame(101, $updated->getHp());

        $this->delete('/admin/enemy/'.$updated->getId()->toRfc4122().'/edit', '/admin/enemy/'.$updated->getId()->toRfc4122().'/delete');
        self::getContainer()->get('doctrine')->getManager()->clear();
        self::assertNull(self::getContainer()->get('doctrine')->getRepository(GameEnemy::class)->findOneBy(['key' => $key]));
    }

    public function testSettingsStructuredFormUpdatesAndRestoresLootLuck(): void
    {
        $this->loginAdmin('settings-crud-admin@grrind.app');
        $settings = self::getContainer()->get('doctrine')->getRepository(GameSettings::class)->find(1);
        self::assertInstanceOf(GameSettings::class, $settings);
        $original = $settings->getLootLuck();
        $newFloor = $original['floor_percent'] + 1;

        $crawler = $this->client->request('GET', '/admin/settings/1/edit');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="GameSettings"]')->form();
        $form->setValues(['GameSettings[lootLuck][floor_percent]' => (string) $newFloor]);
        $this->client->submit($form);
        self::assertResponseRedirects();
        self::getContainer()->get('doctrine')->getManager()->clear();
        $updated = self::getContainer()->get('doctrine')->getRepository(GameSettings::class)->find(1);
        self::assertInstanceOf(GameSettings::class, $updated);
        self::assertSame($newFloor, $updated->getLootLuck()['floor_percent']);

        $restore = $this->client->request('GET', '/admin/settings/1/edit')->filter('form[name="GameSettings"]')->form();
        $restore->setValues(['GameSettings[lootLuck][floor_percent]' => (string) $original['floor_percent']]);
        $this->client->submit($restore);
        self::assertResponseRedirects();
    }

    public function testInactiveItemUploadCanBeCreatedUpdatedAndDeletedThroughAdmin(): void
    {
        $this->loginAdmin('item-crud-admin@grrind.app');
        $key = 'HTTP_ITEM_'.bin2hex(random_bytes(4));
        $path = tempnam(sys_get_temp_dir(), 'grrind-item-http-');
        self::assertIsString($path);
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL8ywAAAABJRU5ErkJggg==', true));
        try {
            $crawler = $this->client->request('GET', '/admin/item/new');
            $form = $crawler->filter('form[name="GameItem"]')->form();
            $active = $form['GameItem[active]'];
            self::assertInstanceOf(ChoiceFormField::class, $active);
            $active->untick();
            $upload = $form['GameItem[imagePath][file]'];
            self::assertInstanceOf(FileFormField::class, $upload);
            $upload->upload($path);
            $form->setValues([
                'GameItem[key]' => $key,
                'GameItem[sortOrder]' => (string) random_int(3_000_001, 4_000_000),
                'GameItem[rarity]' => 'COMMON',
                'GameItem[kind]' => 'EQUIPMENT',
                'GameItem[slot]' => 'FEET',
                'GameItem[priceCoins]' => '1',
                'GameItem[shopMinimumLevel]' => '',
                'GameItem[translations][fr][name]' => 'Objet HTTP',
                'GameItem[translations][en][name]' => 'HTTP item',
            ]);
            $this->client->submit($form);
            self::assertResponseRedirects();
            $item = self::getContainer()->get('doctrine')->getRepository(GameItem::class)->findOneBy(['key' => $key]);
            self::assertInstanceOf(GameItem::class, $item);
            self::assertFalse($item->isActive());
            self::assertNotSame('placeholder.png', $item->getImagePath());

            $crawler = $this->client->request('GET', '/admin/item/'.$item->getId()->toRfc4122().'/edit');
            $edit = $crawler->filter('form[name="GameItem"]')->form();
            $edit->setValues(['GameItem[priceCoins]' => '2']);
            $this->client->submit($edit);
            self::assertResponseRedirects();

            $this->delete('/admin/item/'.$item->getId()->toRfc4122().'/edit', '/admin/item/'.$item->getId()->toRfc4122().'/delete');
            self::getContainer()->get('doctrine')->getManager()->clear();
            self::assertNull(self::getContainer()->get('doctrine')->getRepository(GameItem::class)->findOneBy(['key' => $key]));
        } finally {
            unlink($path);
        }
    }

    private function delete(string $editUrl, string $deleteUrl): void
    {
        $page = $this->client->request('GET', $editUrl);
        $token = $page->filter('#action-confirmation-form input[name="token"]')->attr('value');
        self::assertIsString($token);
        $this->client->request('POST', $deleteUrl, ['token' => $token]);
        self::assertResponseRedirects();
    }

    private function loginAdmin(string $email = 'crud-http-admin@grrind.app'): void
    {
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
