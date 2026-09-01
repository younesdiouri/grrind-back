<?php

declare(strict_types=1);

namespace App\Admin\UI\EasyAdmin;

use App\Admin\Domain\GameSettings;
use App\Admin\UI\Form\FighterType;
use App\Admin\UI\Form\LootLuckType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;

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
        yield StructuredField::new('fighter')->setFormType(FighterType::class);
        yield StructuredField::new('lootLuck')->setFormType(LootLuckType::class);
    }
}
