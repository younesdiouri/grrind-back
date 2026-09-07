<?php

declare(strict_types=1);

namespace App\Admin\UI\EasyAdmin;

use App\Admin\Domain\GameEnemy;
use App\Admin\UI\Form\TranslationsType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\ReplacedFileBehavior;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\Image;

final class EnemyCrudController extends GameCrudController
{
    public static function getEntityFqcn(): string
    {
        return GameEnemy::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)->setSearchFields(['key'])->setDefaultSort(['boss' => 'ASC', 'sortOrder' => 'ASC'])->showEntityActionsInlined();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add('active')->add('boss')->add('minimumLevel');
    }

    public function configureActions(Actions $actions): Actions
    {
        $pair = Action::new('toggleLootPair', 'Activer/désactiver la paire de loot')
            ->linkToCrudAction('toggleLootPair')
            ->setTemplatePath('admin/easyadmin/action/toggle_loot_pair.html.twig');

        return $actions->add(Crud::PAGE_INDEX, $pair)->add(Crud::PAGE_DETAIL, $pair);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('key')->setFormTypeOption('disabled', Crud::PAGE_EDIT === $pageName);
        yield BooleanField::new('active');
        yield IntegerField::new('sortOrder');
        yield BooleanField::new('boss');
        yield IntegerField::new('minimumLevel');
        yield IntegerField::new('hp');
        yield IntegerField::new('damage');
        yield IntegerField::new('mitigationPermille');
        yield IntegerField::new('extraTurnPermille');
        yield IntegerField::new('dodgePermille');
        foreach (['idleImagePath' => 'Repos', 'attackImagePath' => 'Attaque', 'hitImagePath' => 'Coup reçu'] as $property => $label) {
            yield ImageField::new($property, $label)
                ->setHelp('Pack complet : renseignez les trois poses, ou retirez les trois pour supprimer le pack.')
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
                ->setFormTypeOption('allow_delete', true)
                // Retirer la référence ne doit jamais détruire une URL déjà publiée.
                ->setFormTypeOption('upload_delete', static function (): void {})
                ->setCustomOption(ImageField::OPTION_REPLACED_FILE_BEHAVIOR, ReplacedFileBehavior::KEEP)
                ->setRequired(false)
                ->hideOnIndex();
        }
        yield StructuredField::new('translations')->setFormType(TranslationsType::class)->setFormTypeOption('introduction', true)->hideOnIndex();
    }
}
