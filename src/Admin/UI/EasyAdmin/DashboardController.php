<?php

declare(strict_types=1);

namespace App\Admin\UI\EasyAdmin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[AdminDashboard(routePath: '/admin', routeName: 'admin', allowedControllers: [ItemCrudController::class, TitleCrudController::class, EnemyCrudController::class, LootTableCrudController::class, SettingsCrudController::class, UserCrudController::class, BattleCrudController::class, InventoryCrudController::class, XpTransactionCrudController::class, CoinTransactionCrudController::class])]
final class DashboardController extends AbstractDashboardController
{
    public function __construct(private readonly CsrfTokenManagerInterface $csrf)
    {
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()->setTitle('GRRIND · Administration');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Configuration du jeu', 'fa fa-gamepad');
        yield MenuItem::linkTo(ItemCrudController::class, 'Items', 'fa fa-cube');
        yield MenuItem::linkTo(TitleCrudController::class, 'Titres', 'fa fa-trophy');
        yield MenuItem::linkTo(EnemyCrudController::class, 'Ennemis et boss', 'fa fa-dragon');
        yield MenuItem::linkTo(LootTableCrudController::class, 'Tables de loot', 'fa fa-dice');
        yield MenuItem::linkTo(SettingsCrudController::class, 'Réglages globaux', 'fa fa-sliders');
        yield MenuItem::section('Lecture seule');
        yield MenuItem::linkTo(UserCrudController::class, 'Comptes', 'fa fa-users');
        yield MenuItem::linkTo(BattleCrudController::class, 'Batailles', 'fa fa-shield');
        yield MenuItem::linkTo(InventoryCrudController::class, 'Inventaires', 'fa fa-box');
        yield MenuItem::linkTo(XpTransactionCrudController::class, 'Transactions XP', 'fa fa-star');
        yield MenuItem::linkTo(CoinTransactionCrudController::class, 'Transactions pièces', 'fa fa-coins');
        yield MenuItem::section('Session');
        yield MenuItem::linkToRoute('Déconnexion', 'fa fa-sign-out', 'admin_logout', ['_csrf_token' => $this->csrf->getToken('logout')->getValue()]);
    }
}
