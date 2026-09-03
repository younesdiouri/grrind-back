<?php

declare(strict_types=1);

namespace App\Admin\UI\EasyAdmin;

use App\Admin\Domain\GameLevel;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

final class LevelCrudController extends GameCrudController
{
    public static function getEntityFqcn(): string
    {
        return GameLevel::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setDefaultSort(['level' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IntegerField::new('level')->setFormTypeOption('disabled', Crud::PAGE_EDIT === $pageName);
        yield IntegerField::new('totalXp');
        yield IntegerField::new('skillPoints');
    }
}
