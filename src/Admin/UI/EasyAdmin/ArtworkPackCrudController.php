<?php

declare(strict_types=1);

namespace App\Admin\UI\EasyAdmin;

use App\Admin\Domain\GameArtworkPack;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/** Les images et ancrages changent sans republier les règles ou reconstruire l’app. */
/** @extends AbstractCrudController<GameArtworkPack> */
final class ArtworkPackCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return GameArtworkPack::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInPlural('Avatars et calques')->setDefaultSort(['sortOrder' => 'ASC', 'key' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::DELETE, Action::BATCH_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        $existing = Crud::PAGE_EDIT === $pageName;
        yield TextField::new('key', 'Clé stable')->setFormTypeOption('disabled', $existing)
            ->setHelp('Avatar : inclure la version, par exemple RUNNER_V1. Objet : clé du catalogue des items.');
        yield ChoiceField::new('kind', 'Type')->setChoices(['Avatar' => 'AVATAR', 'Équipement' => 'EQUIPMENT'])->setFormTypeOption('disabled', $existing);
        yield TextField::new('version')->setFormTypeOption('disabled', $existing);
        yield TextField::new('name', 'Nom affiché');
        yield BooleanField::new('active', 'Disponible')->renderAsSwitch(false)
            ->setHelp('Un avatar désactivé reste visible dans les anciens combats et disparaît du choix utilisateur.');
        yield IntegerField::new('sortOrder', 'Ordre');
        yield TextareaField::new('manifestJson', 'Images, ancrages et compatibilités (JSON)')
            ->setNumOfRows(28)->hideOnIndex()
            ->setHelp('7 poses × 2 vues. Les chemins /artwork/ sont publics. Les transformations sont normalisées sur le canevas. Créer une nouvelle clé/version pour remplacer un pack utilisé.');
    }
}
