<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Identity\Domain\Role;
use App\Identity\Infrastructure\Doctrine\UserRepository;
use App\Tests\Support\ApiTestCase;

/** La page HTTP doit conserver les contraintes serveur, pas seulement la configuration PHP. */
final class ItemAdminFormTest extends ApiTestCase
{
    public function testNewItemFormExposesTheRequiredRestrictedImageUpload(): void
    {
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/item/new');
        self::assertResponseIsSuccessful();
        $upload = $crawler->filter('input[type="file"]');
        self::assertCount(1, $upload);
        self::assertSame('required', $upload->attr('required'));
        self::assertSame('image/jpeg,image/png,image/webp', $upload->attr('accept'));
    }

    public function testEveryStructuredConfigurationFormRendersItsNestedFields(): void
    {
        $this->loginAdmin('structured-form-admin@grrind.app');

        $title = $this->client->request('GET', '/admin/title/new');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $title->filter('input[name$="[translations][fr][name]"]'));

        $enemy = $this->client->request('GET', '/admin/enemy/new');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $enemy->filter('input[name$="[translations][en][name]"]'));

        $loot = $this->client->request('GET', '/admin/loot-table/new');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $loot->filter('select[name$="[eligibility][disciplines][]"]'));

        $settings = $this->client->request('GET', '/admin/settings/1/edit');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $settings->filter('input[name$="[fighter][base_hp]"]'));
        self::assertCount(1, $settings->filter('input[name$="[lootLuck][floor_percent]"]'));
    }

    private function loginAdmin(string $email = 'item-form-admin@grrind.app'): void
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
