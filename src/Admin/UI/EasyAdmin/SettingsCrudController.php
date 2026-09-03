<?php

declare(strict_types=1);

namespace App\Admin\UI\EasyAdmin;

use App\Admin\Domain\GameSettings;
use App\Admin\UI\Form\AttributesType;
use App\Admin\UI\Form\CommunityType;
use App\Admin\UI\Form\FighterType;
use App\Admin\UI\Form\LootLuckType;
use App\Admin\UI\Form\NotificationsType;
use App\Admin\UI\Form\TrainingType;
use App\Admin\UI\Form\XpType;
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
        yield StructuredField::new('training')->setFormType(TrainingType::class)->hideOnIndex();
        yield StructuredField::new('xp')->setFormType(XpType::class)->hideOnIndex();
        yield StructuredField::new('attributes')->setFormType(AttributesType::class)->hideOnIndex();
        yield StructuredField::new('community')->setFormType(CommunityType::class)->hideOnIndex();
        yield StructuredField::new('notifications')->setFormType(NotificationsType::class)->hideOnIndex();
    }
}
