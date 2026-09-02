<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Identity\Domain\Role;
use App\Identity\Infrastructure\Doctrine\UserRepository;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\DomCrawler\Crawler;

/** Le tableau de bord est le point d'entrée opérable des dix CRUD administratifs. */
final class DashboardTest extends ApiTestCase
{
    public function testTrustedFlyProxyKeepsTheAdminLoginRedirectOnHttps(): void
    {
        $this->client->request('GET', '/admin', [], [], [
            'HTTP_HOST' => 'grrind-back.fly.dev',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        self::assertResponseRedirects('https://grrind-back.fly.dev/admin/login');
    }

    public function testDashboardShowsEveryCrudWithoutTheEasyAdminWelcomePage(): void
    {
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('GRRIND', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Welcome to EasyAdmin', (string) $this->client->getResponse()->getContent());

        $dashboard = $crawler->filter('#grrind-admin-dashboard');
        self::assertCount(1, $dashboard);

        foreach ([
            'Items',
            'Titres',
            'Ennemis et boss',
            'Tables de loot',
            'Réglages globaux',
            'Comptes',
            'Batailles',
            'Inventaires',
            'Transactions XP',
            'Transactions pièces',
        ] as $label) {
            $link = $dashboard->selectLink($label);
            self::assertCount(1, $link, \sprintf('Le dashboard doit lier « %s ». ', $label));
            $this->assertCrudLinkWorks($link);
        }
    }

    private function loginAdmin(): void
    {
        $email = 'dashboard@grrind.app';
        $this->openAccount($email);
        $users = self::getContainer()->get(UserRepository::class);
        $user = $users->ofEmail($email);
        self::assertNotNull($user);
        $user->grant(Role::Admin);
        $users->commit();

        $crawler = $this->client->request('GET', '/admin/login');
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');
        self::assertIsString($token);
        $this->client->request('POST', '/admin/login', [
            '_username' => $email,
            '_password' => 'un-mot-de-passe-assez-long',
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/admin');
    }

    private function assertCrudLinkWorks(Crawler $link): void
    {
        $uri = $link->link()->getUri();
        $path = parse_url($uri, \PHP_URL_PATH);
        self::assertIsString($path);

        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();
    }
}
