<?php

declare(strict_types=1);

namespace App\Admin\UI\EasyAdmin;

use App\Admin\Domain\GameEnemy;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class EnemyCrudController extends GameCrudController
{
    public static function getEntityFqcn(): string
    {
        return GameEnemy::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('key');
        yield BooleanField::new('active');
        yield IntegerField::new('sortOrder');
        yield BooleanField::new('boss');
        yield IntegerField::new('minimumLevel');
        yield IntegerField::new('hp');
        yield IntegerField::new('damage');
        yield IntegerField::new('mitigationPermille');
        yield IntegerField::new('extraTurnPermille');
        yield IntegerField::new('dodgePermille');
        yield TextareaField::new('translations')->hideOnIndex();
    }
}
