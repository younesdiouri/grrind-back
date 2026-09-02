<?php

declare(strict_types=1);

namespace App\Admin\UI\EasyAdmin;

use App\Admin\Domain\GameLootTable;
use App\Admin\UI\Form\EligibilityType;
use App\Admin\UI\Form\LootEntryType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class LootTableCrudController extends GameCrudController
{
    public static function getEntityFqcn(): string
    {
        return GameLootTable::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setSearchFields(['key', 'kind'])->setDefaultSort(['kind' => 'ASC', 'sortOrder' => 'ASC'])->showEntityActionsInlined();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('active')->add('kind');
    }

    public function configureActions(Actions $actions): Actions
    {
        $pair = Action::new('toggleLootPair', 'Activer/désactiver la paire')
            ->linkToCrudAction('toggleLootPair')
            ->displayIf(static fn (GameLootTable $table): bool => \in_array($table->getKind(), ['adversary', 'chest'], true))
            ->setTemplatePath('admin/easyadmin/action/toggle_loot_pair.html.twig');

        return $actions->add(Crud::PAGE_INDEX, $pair)->add(Crud::PAGE_DETAIL, $pair);
    }

    public function configureFields(string $pageName): iterable
    {
        yield ChoiceField::new('kind')->setChoices(['Séance' => 'workout', 'Adversaire' => 'adversary', 'Coffre' => 'chest'])->setFormTypeOption('disabled', Crud::PAGE_EDIT === $pageName);
        yield TextField::new('key')->setFormTypeOption('disabled', Crud::PAGE_EDIT === $pageName);
        yield BooleanField::new('active');
        yield IntegerField::new('sortOrder');
        yield StructuredField::new('eligibility')->setFormType(EligibilityType::class)->hideOnIndex();
        yield IntegerField::new('coinsMinimum');
        yield IntegerField::new('coinsMaximum');
        yield CollectionField::new('entries')->setEntryType(LootEntryType::class)->allowAdd()->allowDelete()->hideOnIndex();
    }
}
