<?php

declare(strict_types=1);

namespace App\Admin\UI\EasyAdmin;

use App\Admin\Domain\GameSettings;
use App\Admin\UI\Form\FighterType;
use App\Admin\UI\Form\LootLuckType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

final class SettingsCrudController extends GameCrudController
{
    public static function getEntityFqcn(): string
    {
        return GameSettings::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::DELETE);
    }

    public function configureCrud(Crud $crud): Crud
    {
        // Le réglage global est un singleton, mais son index doit rester déterministe comme
        // les catalogues ordonnés pour que le lien dashboard ne dépende pas du SGBD.
        return parent::configureCrud($crud)->setDefaultSort(['id' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IntegerField::new('id')->onlyOnIndex();
        yield StructuredField::new('fighter')->setFormType(FighterType::class)->hideOnIndex();
        yield StructuredField::new('lootLuck')->setFormType(LootLuckType::class)->hideOnIndex();
    }
}
