<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\UI\EasyAdmin\ActivityTypeCrudController;
use App\Admin\UI\EasyAdmin\BattleCrudController;
use App\Admin\UI\EasyAdmin\CoinTransactionCrudController;
use App\Admin\UI\EasyAdmin\DisciplineCrudController;
use App\Admin\UI\EasyAdmin\EnemyCrudController;
use App\Admin\UI\EasyAdmin\InventoryCrudController;
use App\Admin\UI\EasyAdmin\ItemCrudController;
use App\Admin\UI\EasyAdmin\LevelCrudController;
use App\Admin\UI\EasyAdmin\LootTableCrudController;
use App\Admin\UI\EasyAdmin\SettingsCrudController;
use App\Admin\UI\EasyAdmin\TitleCrudController;
use App\Admin\UI\EasyAdmin\UserCrudController;
use App\Admin\UI\EasyAdmin\XpTransactionCrudController;
use App\Identity\Domain\Role;
use App\Identity\Infrastructure\Doctrine\UserRepository;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;

/** Le tableau de bord est le point d'entrée opérable des dix CRUD administratifs. */
final class DashboardTest extends ApiTestCase
{
    /** @var array<string, class-string> */
    private const array CRUDS = [
        'Items' => ItemCrudController::class,
        'Titres' => TitleCrudController::class,
        'Ennemis et boss' => EnemyCrudController::class,
        'Tables de loot' => LootTableCrudController::class,
        'Disciplines' => DisciplineCrudController::class,
        'Niveaux' => LevelCrudController::class,
        'Types d’activité' => ActivityTypeCrudController::class,
        'Réglages globaux' => SettingsCrudController::class,
        'Comptes' => UserCrudController::class,
        'Batailles' => BattleCrudController::class,
        'Inventaires' => InventoryCrudController::class,
        'Transactions XP' => XpTransactionCrudController::class,
        'Transactions pièces' => CoinTransactionCrudController::class,
    ];

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

        foreach (self::CRUDS as $label => $controller) {
            $link = $dashboard->selectLink($label);
            self::assertCount(1, $link, \sprintf('Le dashboard doit lier « %s ». ', $label));
            $this->assertCrudLinkWorks($link, $controller);
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

    /** @param class-string $controller */
    private function assertCrudLinkWorks(Crawler $link, string $controller): void
    {
        $uri = $link->link()->getUri();
        $path = parse_url($uri, \PHP_URL_PATH);
        self::assertIsString($path);
        $matcher = self::getContainer()->get('router.default');
        self::assertInstanceOf(UrlMatcherInterface::class, $matcher);
        $route = $matcher->match($path);
        self::assertSame($controller.'::index', $route['_controller'] ?? null);

        $query = parse_url($uri, \PHP_URL_QUERY);
        self::assertTrue(null === $query || \is_string($query));
        $requestUri = $path.(\is_string($query) && '' !== $query ? '?'.$query : '');

        $this->client->request('GET', $requestUri);
        self::assertResponseIsSuccessful();
    }
}
