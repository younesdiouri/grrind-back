<?php

declare(strict_types=1);

namespace App\Admin\UI\EasyAdmin;

use App\Identity\Domain\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class UserCrudController extends ReadOnlyCrudController
{
    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setSearchFields(['email', 'displayName'])->setPaginatorPageSize(30);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('email');
        yield TextField::new('displayName');
        yield DateTimeField::new('registeredAt');
    }
}
