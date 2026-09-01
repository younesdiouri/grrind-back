<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Identity\Domain\Role;
use App\Identity\Infrastructure\Doctrine\UserRepository;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\HttpFoundation\Response;

/** Le dashboard doit conserver une session Symfony distincte des jetons mobiles. */
final class AdminSecurityTest extends ApiTestCase
{
    public function testAnonymousAndPlayerAreRefusedWhileAdminCanOpenDashboard(): void
    {
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/login');

        $this->openAccount('admin-security@grrind.app');
        $this->login('admin-security@grrind.app', 'un-mot-de-passe-assez-long');
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $users = self::getContainer()->get(UserRepository::class);
        $user = $users->ofEmail('admin-security@grrind.app');
        self::assertNotNull($user);
        $user->grant(Role::Admin);
        $users->commit();

        $this->login('admin-security@grrind.app', 'un-mot-de-passe-assez-long');
        self::assertResponseRedirects('/admin');
    }

    public function testLoginRejectsWrongPassword(): void
    {
        $this->openAccount('wrong-password@grrind.app');
        $this->login('wrong-password@grrind.app', 'incorrect');

        self::assertResponseRedirects('/admin/login');
    }

    private function login(string $email, string $password): void
    {
        $crawler = $this->client->request('GET', '/admin/login');
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');
        self::assertIsString($token);
        $this->client->request('POST', '/admin/login', [
            '_username' => $email,
            '_password' => $password,
            '_csrf_token' => $token,
        ]);
    }
}
