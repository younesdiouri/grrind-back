<?php

declare(strict_types=1);

namespace App\Admin\UI\EasyAdmin;

use App\Admin\Domain\GameLootTable;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class LootTableCrudController extends GameCrudController
{
    public static function getEntityFqcn(): string
    {
        return GameLootTable::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield ChoiceField::new('kind')->setChoices(['Séance' => 'workout', 'Adversaire' => 'adversary', 'Coffre' => 'chest']);
        yield TextField::new('key');
        yield BooleanField::new('active');
        yield IntegerField::new('sortOrder');
        yield TextareaField::new('eligibility')->hideOnIndex();
        yield IntegerField::new('coinsMinimum');
        yield IntegerField::new('coinsMaximum');
        yield TextareaField::new('entries')->hideOnIndex();
    }
}
