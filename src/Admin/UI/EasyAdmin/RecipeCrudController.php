<?php

declare(strict_types=1);

namespace App\Admin\UI\EasyAdmin;

use App\Admin\Domain\GameRecipe;
use App\Admin\UI\Form\RecipeCostType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Validator\Constraints as Assert;

final class RecipeCrudController extends GameCrudController
{
    public static function getEntityFqcn(): string
    {
        return GameRecipe::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('key')->setFormTypeOption('disabled', Crud::PAGE_EDIT === $pageName);
        yield BooleanField::new('active');
        yield IntegerField::new('sortOrder');
        yield TextField::new('resultItem', 'Clé de l’équipement produit');
        yield IntegerField::new('quantity', 'Quantité produite')->setFormTypeOption('constraints', [new Assert\Positive()]);
        yield CollectionField::new('costs', 'Ressources consommées')->setEntryType(RecipeCostType::class)->allowAdd()->allowDelete()->hideOnIndex();
    }
}
