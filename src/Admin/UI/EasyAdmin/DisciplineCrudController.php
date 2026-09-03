<?php

declare(strict_types=1);

namespace App\Admin\UI\EasyAdmin;

use App\Admin\Domain\GameDiscipline;
use App\Admin\UI\Form\AttributeSplitType;
use App\Admin\UI\Form\TranslationsType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use Symfony\Component\Validator\Constraints\Positive;

final class DisciplineCrudController extends GameCrudController
{
    public static function getEntityFqcn(): string
    {
        return GameDiscipline::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setSearchFields(['discipline'])->setDefaultSort(['sortOrder' => 'ASC']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('active')->add('creditsXp');
    }

    public function configureFields(string $pageName): iterable
    {
        yield ChoiceField::new('discipline')->setChoices(array_combine(array_map(static fn ($discipline): string => $discipline->value, \App\Shared\Domain\Activity\Discipline::cases()), \App\Shared\Domain\Activity\Discipline::cases()) ?: [])->setFormTypeOption('disabled', Crud::PAGE_EDIT === $pageName);
        yield BooleanField::new('active');
        yield IntegerField::new('sortOrder');
        yield BooleanField::new('creditsXp');
        yield IntegerField::new('dailyCapXp');
        yield IntegerField::new('xpPerKm')->setFormTypeOption('constraints', [new Positive()]);
        yield IntegerField::new('xpPer100mElevation')->setFormTypeOption('constraints', [new Positive()]);
        yield StructuredField::new('split')->setFormType(AttributeSplitType::class)->hideOnIndex();
        yield StructuredField::new('translations')->setFormType(TranslationsType::class)->hideOnIndex();
    }
}
