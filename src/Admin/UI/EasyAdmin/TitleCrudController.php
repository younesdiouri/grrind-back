<?php

declare(strict_types=1);

namespace App\Admin\UI\EasyAdmin;

use App\Admin\Domain\GameTitle;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class TitleCrudController extends GameCrudController
{
    public static function getEntityFqcn(): string
    {
        return GameTitle::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('key');
        yield BooleanField::new('active');
        yield IntegerField::new('sortOrder');
        yield TextField::new('conditionType');
        yield IntegerField::new('threshold');
        yield TextField::new('discipline');
        yield TextareaField::new('translations')->hideOnIndex();
    }
}
