<?php

declare(strict_types=1);

namespace App\Admin\UI\EasyAdmin;

use App\Admin\Domain\GameTitle;
use App\Admin\UI\Form\TranslationsType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class TitleCrudController extends GameCrudController
{
    public static function getEntityFqcn(): string
    {
        return GameTitle::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('key')->setFormTypeOption('disabled', Crud::PAGE_EDIT === $pageName);
        yield BooleanField::new('active');
        yield IntegerField::new('sortOrder');
        yield TextField::new('conditionType');
        yield IntegerField::new('threshold');
        yield TextField::new('discipline');
        yield CollectionField::new('translations')->setEntryType(TranslationsType::class)->hideOnIndex();
    }
}
