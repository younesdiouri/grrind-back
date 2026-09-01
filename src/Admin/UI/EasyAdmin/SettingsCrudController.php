<?php

declare(strict_types=1);

namespace App\Admin\UI\EasyAdmin;

use App\Admin\Domain\GameSettings;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;

final class SettingsCrudController extends GameCrudController
{
    public static function getEntityFqcn(): string
    {
        return GameSettings::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextareaField::new('fighter');
        yield TextareaField::new('lootLuck');
    }
}
