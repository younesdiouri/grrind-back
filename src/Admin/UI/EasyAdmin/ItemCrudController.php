<?php

declare(strict_types=1);

namespace App\Admin\UI\EasyAdmin;

use App\Admin\Domain\GameItem;
use App\Admin\UI\Form\ModifierEntryType;
use App\Admin\UI\Form\TranslationsType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\ReplacedFileBehavior;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\Image;

final class ItemCrudController extends GameCrudController
{
    public static function getEntityFqcn(): string
    {
        return GameItem::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setSearchFields(['key', 'rarity', 'kind'])->setDefaultSort(['sortOrder' => 'ASC'])->showEntityActionsInlined();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('active')->add('rarity')->add('kind')->add('shopAvailable');
    }

    public function configureActions(Actions $actions): Actions
    {
        $pair = Action::new('toggleLootPair', 'Activer/désactiver la paire de loot')
            ->linkToCrudAction('toggleLootPair')
            ->displayIf(static fn (GameItem $item): bool => 'CHEST' === $item->getKind())
            ->setTemplatePath('admin/easyadmin/action/toggle_loot_pair.html.twig');

        return $actions->add(Crud::PAGE_INDEX, $pair)->add(Crud::PAGE_DETAIL, $pair);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('key')->setHelp('Identifiant stable, non renommable après création.')->setFormTypeOption('disabled', Crud::PAGE_EDIT === $pageName);
        yield BooleanField::new('active');
        yield IntegerField::new('sortOrder');
        yield ChoiceField::new('rarity')->setChoices(array_combine(['COMMON', 'UNCOMMON', 'RARE', 'EPIC', 'LEGENDARY'], ['COMMON', 'UNCOMMON', 'RARE', 'EPIC', 'LEGENDARY']));
        yield ChoiceField::new('kind')->setChoices(['Équipement' => 'EQUIPMENT', 'Coffre' => 'CHEST']);
        yield TextField::new('slot')->hideOnIndex();
        yield IntegerField::new('priceCoins');
        yield CollectionField::new('modifiers')->setEntryType(ModifierEntryType::class)->allowAdd()->allowDelete()->hideOnIndex();
        yield BooleanField::new('shopAvailable');
        yield IntegerField::new('shopMinimumLevel')->hideOnIndex();
        yield ImageField::new('imagePath')
            ->setBasePath('/game-images')
            // Le transformeur EasyAdmin relit le fichier courant depuis le volume final pour
            // afficher sa miniature ; seul son écriture est redirigée vers le staging ci-dessous.
            ->setUploadDir($this->gameImageDirectory)
            ->setFormTypeOption('download_path', '/game-images/')
            ->setFormTypeOption('upload_new', function (UploadedFile $file, string $unusedDirectory, string $name): void {
                $file->move($this->stagingImageDirectory(), $name);
            })
            // EasyAdmin calcule le SHA-1, le suffixe UUID isole deux transactions qui ont le
            // même binaire en staging : l'annulation de l'une ne peut plus effacer l'autre.
            ->setUploadedFileNamePattern('[contenthash]-[uuid].[extension]')
            ->setFileConstraints(new Image(maxSize: '2M', mimeTypes: ['image/jpeg', 'image/png', 'image/webp'], maxWidth: 4096, maxHeight: 4096))
            ->mimeTypes('image/jpeg,image/png,image/webp')
            ->setFormTypeOption('allow_delete', false)
            ->setCustomOption(ImageField::OPTION_REPLACED_FILE_BEHAVIOR, ReplacedFileBehavior::KEEP)
            ->setRequired('new' === $pageName);
        yield StructuredField::new('translations')->setFormType(TranslationsType::class)->hideOnIndex();
    }
}
