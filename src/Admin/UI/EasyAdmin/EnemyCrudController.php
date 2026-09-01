<?php

declare(strict_types=1);

namespace App\Admin\UI\EasyAdmin;

use App\Admin\Domain\GameEnemy;
use App\Admin\UI\Form\TranslationsType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class EnemyCrudController extends GameCrudController
{
    public static function getEntityFqcn(): string
    {
        return GameEnemy::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setSearchFields(['key'])->setDefaultSort(['boss' => 'ASC', 'sortOrder' => 'ASC']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('active')->add('boss')->add('minimumLevel');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('key')->setFormTypeOption('disabled', Crud::PAGE_EDIT === $pageName);
        yield BooleanField::new('active');
        yield IntegerField::new('sortOrder');
        yield BooleanField::new('boss');
        yield IntegerField::new('minimumLevel');
        yield IntegerField::new('hp');
        yield IntegerField::new('damage');
        yield IntegerField::new('mitigationPermille');
        yield IntegerField::new('extraTurnPermille');
        yield IntegerField::new('dodgePermille');
        yield Field::new('translations')->setFormType(TranslationsType::class)->hideOnIndex();
    }
}
