<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Doctrine\UserRepository;
use App\Identity\UI\Console\GrantAdminCommand;
use App\Shared\Domain\Timezone;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

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

    public function testLogoutRequiresItsCsrfToken(): void
    {
        $this->openAccount('logout@grrind.app');
        $users = self::getContainer()->get(UserRepository::class);
        $user = $users->ofEmail('logout@grrind.app');
        self::assertNotNull($user);
        $user->grant(Role::Admin);
        $users->commit();
        $this->login('logout@grrind.app', 'un-mot-de-passe-assez-long');

        $this->client->request('GET', '/admin/logout');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $csrf = self::getContainer()->get(CsrfTokenManagerInterface::class);
        $token = $csrf->getToken('logout')->getValue();
        $this->client->request('GET', '/admin/logout?_csrf_token='.$token);
        self::assertResponseRedirects('/admin/login');
    }

    public function testGrantAdminRefusesMissingAndPasswordlessAccountsThenRemainsIdempotent(): void
    {
        $users = self::getContainer()->get(UserRepository::class);
        $command = new GrantAdminCommand($users);

        $missing = new CommandTester($command);
        $missing->execute(['email' => 'absent@grrind.app']);
        self::assertSame(Command::FAILURE, $missing->getStatusCode());
        self::assertStringContainsString('introuvable', $missing->getDisplay());

        $social = User::register('social@grrind.app', 'Social', Timezone::utc(), new DateTimeImmutable());
        $users->add($social);
        $passwordless = new CommandTester($command);
        $passwordless->execute(['email' => 'social@grrind.app']);
        self::assertSame(Command::FAILURE, $passwordless->getStatusCode());
        self::assertStringContainsString('mot de passe', $passwordless->getDisplay());

        $this->openAccount('operator@grrind.app');
        $first = new CommandTester($command);
        $first->execute(['email' => 'operator@grrind.app']);
        $second = new CommandTester($command);
        $second->execute(['email' => 'operator@grrind.app']);
        self::assertSame(Command::SUCCESS, $first->getStatusCode());
        self::assertSame(Command::SUCCESS, $second->getStatusCode());
        self::assertContains(Role::Admin->value, $users->ofEmail('operator@grrind.app')?->getRoles() ?? []);
    }

    public function testReadOnlyScreensRenderButTheirDirectMutationActionsAreDenied(): void
    {
        $this->openAccount('readonly@grrind.app');
        $users = self::getContainer()->get(UserRepository::class);
        $admin = $users->ofEmail('readonly@grrind.app');
        self::assertNotNull($admin);
        $admin->grant(Role::Admin);
        $users->commit();
        $this->login('readonly@grrind.app', 'un-mot-de-passe-assez-long');

        foreach (['/admin/battle', '/admin/inventory'] as $index) {
            $this->client->request('GET', $index);
            self::assertResponseIsSuccessful();

            $this->client->request('GET', $index.'/new');
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        }
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
