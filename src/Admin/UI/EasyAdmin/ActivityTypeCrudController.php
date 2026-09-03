<?php

declare(strict_types=1);

namespace App\Admin\UI\EasyAdmin;

use App\Admin\Domain\GameActivityType;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Activity\WorkoutSource;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class ActivityTypeCrudController extends GameCrudController
{
    public static function getEntityFqcn(): string
    {
        return GameActivityType::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setSearchFields(['providerType'])->setDefaultSort(['source' => 'ASC', 'providerType' => 'ASC']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('source')->add('discipline')->add('active');
    }

    public function configureFields(string $pageName): iterable
    {
        yield ChoiceField::new('source')->setChoices(array_combine(array_map(static fn (WorkoutSource $source): string => $source->value, WorkoutSource::cases()), WorkoutSource::cases()) ?: []);
        yield TextField::new('providerType');
        yield ChoiceField::new('discipline')->setChoices(array_combine(array_map(static fn (Discipline $discipline): string => $discipline->value, Discipline::cases()), Discipline::cases()) ?: []);
        yield BooleanField::new('active');
    }
}
