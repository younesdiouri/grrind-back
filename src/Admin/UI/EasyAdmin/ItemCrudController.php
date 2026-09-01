<?php

declare(strict_types=1);

namespace App\Admin\UI\EasyAdmin;

use App\Admin\Domain\GameItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\ReplacedFileBehavior;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Validator\Constraints\Image;

final class ItemCrudController extends GameCrudController
{
    public static function getEntityFqcn(): string
    {
        return GameItem::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setSearchFields(['key', 'rarity', 'kind']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('key')->setHelp('Identifiant stable, non renommable après création.');
        yield BooleanField::new('active');
        yield IntegerField::new('sortOrder');
        yield ChoiceField::new('rarity')->setChoices(array_combine(['COMMON', 'UNCOMMON', 'RARE', 'EPIC', 'LEGENDARY'], ['COMMON', 'UNCOMMON', 'RARE', 'EPIC', 'LEGENDARY']));
        yield ChoiceField::new('kind')->setChoices(['Équipement' => 'EQUIPMENT', 'Coffre' => 'CHEST']);
        yield TextField::new('slot')->hideOnIndex();
        yield IntegerField::new('priceCoins');
        yield TextareaField::new('modifiers')->hideOnIndex();
        yield BooleanField::new('shopAvailable');
        yield IntegerField::new('shopMinimumLevel')->hideOnIndex();
        yield ImageField::new('imagePath')
            ->setBasePath('/admin/images')
            ->setUploadDir($this->gameImageDirectory)
            ->setUploadedFileNamePattern('[contenthash].[extension]')
            ->setFileConstraints(new Image(maxSize: '2M', mimeTypes: ['image/jpeg', 'image/png', 'image/webp'], maxWidth: 4096, maxHeight: 4096))
            ->mimeTypes('image/jpeg,image/png,image/webp')
            ->setFormTypeOption('allow_delete', false)
            ->setCustomOption(ImageField::OPTION_REPLACED_FILE_BEHAVIOR, ReplacedFileBehavior::KEEP)
            ->setRequired('new' === $pageName);
        yield TextareaField::new('translations')->hideOnIndex();
    }
}
