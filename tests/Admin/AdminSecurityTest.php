<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Doctrine\UserRepository;
use App\Identity\UI\Console\GrantAdminCommand;
use App\Progression\Application\GrantXp;
use App\Progression\Application\GrantXpHandler;
use App\Progression\Domain\XpTransaction;
use App\Rewards\Domain\CoinReason;
use App\Rewards\Infrastructure\Doctrine\CoinTransactionRepository;
use App\Rewards\Infrastructure\Doctrine\InventoryItemRepository;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Timezone;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Battles;
use DateTimeImmutable;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;

/** Le dashboard doit conserver une session Symfony distincte des jetons mobiles. */
final class AdminSecurityTest extends ApiTestCase
{
    use Battles;

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

    public function testLoginRejectsAnInvalidCsrfTokenBeforeCheckingCredentials(): void
    {
        $this->openAccount('csrf-login@grrind.app');
        $this->client->request('POST', '/admin/login', [
            '_username' => 'csrf-login@grrind.app',
            '_password' => 'un-mot-de-passe-assez-long',
            '_csrf_token' => 'forged-token',
        ]);

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

        $this->client->request('GET', '/admin/logout?_csrf_token=forged-token');
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

        foreach (['/admin/user', '/admin/battle', '/admin/inventory', '/admin/xp-transaction', '/admin/coin-transaction'] as $index) {
            $this->client->request('GET', $index);
            self::assertResponseIsSuccessful();

            $this->client->request('GET', $index.'/new');
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

            $this->client->request('POST', $index.'/batch-delete', [
                'batchActionName' => 'batch_delete',
                'batchActionEntityIds' => [],
                'entityFqcn' => User::class,
                'batchActionCsrfToken' => 'bypass-attempt',
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        }
    }

    public function testReadOnlyDetailsExposeOnlySafeFactsAndRefuseEveryMutationRoute(): void
    {
        $account = $this->openAccount('readonly-details@grrind.app');
        $users = self::getContainer()->get(UserRepository::class);
        $admin = $users->ofEmail('readonly-details@grrind.app');
        self::assertNotNull($admin);
        $admin->grant(Role::Admin);
        $users->commit();

        $now = new DateTimeImmutable();
        $battleId = $this->recordBattle($account, $now);
        $inventory = self::getContainer()->get(InventoryItemRepository::class)->grant($account->id, 'WORN_RUNNING_SHOES', null, $now);
        $coins = self::getContainer()->get(CoinTransactionRepository::class)->record($account->id, CoinReason::WorkoutDrop, Uuid::v7(), 1, $now);
        $grantXp = self::getContainer()->get(GrantXpHandler::class);
        $grantXp(new GrantXp($account->id, Uuid::v7(), Discipline::Running, 3_600, $now));
        $xp = self::getContainer()->get('doctrine')->getRepository(XpTransaction::class)->findOneBy(['userId' => $account->id]);
        self::assertInstanceOf(XpTransaction::class, $xp);

        $this->login('readonly-details@grrind.app', 'un-mot-de-passe-assez-long');
        $details = [
            '/admin/user' => $account->id->toRfc4122(),
            '/admin/battle' => $battleId,
            '/admin/inventory' => $inventory->id()->toRfc4122(),
            '/admin/xp-transaction' => $xp->id()->toRfc4122(),
            '/admin/coin-transaction' => $coins->id()->toRfc4122(),
        ];

        foreach ($details as $path => $id) {
            $this->client->request('GET', $path.'/'.$id);
            self::assertResponseIsSuccessful();
            $content = strtolower((string) $this->client->getResponse()->getContent());
            self::assertStringNotContainsString('seed', $content);
            self::assertStringNotContainsString('access token', $content);
            self::assertStringNotContainsString('refresh token', $content);

            $this->client->request('GET', $path.'/'.$id.'/edit');
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

            $this->client->request('POST', $path.'/'.$id.'/delete', ['token' => 'bypass-attempt']);
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
