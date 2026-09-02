<?php

declare(strict_types=1);

namespace App\Admin\UI\EasyAdmin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[AdminDashboard(routePath: '/admin', routeName: 'admin', allowedControllers: [ItemCrudController::class, TitleCrudController::class, EnemyCrudController::class, LootTableCrudController::class, SettingsCrudController::class, UserCrudController::class, BattleCrudController::class, InventoryCrudController::class, XpTransactionCrudController::class, CoinTransactionCrudController::class])]
final class DashboardController extends AbstractDashboardController
{
    /** @var array<class-string, array{label: string, icon: string}> */
    private const array CONFIGURATION_CRUDS = [
        ItemCrudController::class => ['label' => 'Items', 'icon' => 'fa fa-cube'],
        TitleCrudController::class => ['label' => 'Titres', 'icon' => 'fa fa-trophy'],
        EnemyCrudController::class => ['label' => 'Ennemis et boss', 'icon' => 'fa fa-dragon'],
        LootTableCrudController::class => ['label' => 'Tables de loot', 'icon' => 'fa fa-dice'],
        SettingsCrudController::class => ['label' => 'Réglages globaux', 'icon' => 'fa fa-sliders'],
    ];

    /** @var array<class-string, array{label: string, icon: string}> */
    private const array READ_ONLY_CRUDS = [
        UserCrudController::class => ['label' => 'Comptes', 'icon' => 'fa fa-users'],
        BattleCrudController::class => ['label' => 'Batailles', 'icon' => 'fa fa-shield'],
        InventoryCrudController::class => ['label' => 'Inventaires', 'icon' => 'fa fa-box'],
        XpTransactionCrudController::class => ['label' => 'Transactions XP', 'icon' => 'fa fa-star'],
        CoinTransactionCrudController::class => ['label' => 'Transactions pièces', 'icon' => 'fa fa-coins'],
    ];

    public function __construct(
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly AdminUrlGenerator $adminUrls,
    ) {
    }

    public function index(): Response
    {
        return $this->render('admin/easyadmin/dashboard.html.twig', [
            'sections' => [
                'Configuration du jeu' => $this->linksFor(self::CONFIGURATION_CRUDS),
                'Lecture seule' => $this->linksFor(self::READ_ONLY_CRUDS),
            ],
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()->setTitle('GRRIND · Administration');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Configuration du jeu', 'fa fa-gamepad');
        foreach (self::CONFIGURATION_CRUDS as $controller => $crud) {
            yield MenuItem::linkTo($controller, $crud['label'], $crud['icon']);
        }

        yield MenuItem::section('Lecture seule');
        foreach (self::READ_ONLY_CRUDS as $controller => $crud) {
            yield MenuItem::linkTo($controller, $crud['label'], $crud['icon']);
        }

        yield MenuItem::section('Session');
        yield MenuItem::linkToRoute('Déconnexion', 'fa fa-sign-out', 'admin_logout', ['_csrf_token' => $this->csrf->getToken('logout')->getValue()]);
    }

    /**
     * EasyAdmin construit les routes depuis le dashboard et le contrôleur, au lieu de figer
     * des chemins qui divergeraient dès qu'une route CRUD change.
     *
     * @param array<class-string, array{label: string, icon: string}> $cruds
     *
     * @return list<array{label: string, icon: string, url: string}>
     */
    private function linksFor(array $cruds): array
    {
        $links = [];
        foreach ($cruds as $controller => $crud) {
            $links[] = [
                'label' => $crud['label'],
                'icon' => $crud['icon'],
                'url' => $this->adminUrls
                    ->unsetAll()
                    ->setDashboard(self::class)
                    ->setController($controller)
                    ->setAction(Action::INDEX)
                    ->generateUrl(),
            ];
        }

        return $links;
    }
}
