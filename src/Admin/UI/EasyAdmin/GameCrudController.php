<?php

declare(strict_types=1);

namespace App\Admin\UI\EasyAdmin;

use App\Admin\Domain\GameEnemy;
use App\Admin\Domain\GameItem;
use App\Admin\Domain\GameLootTable;
use App\Admin\Infrastructure\GameConfigurationReferenceGuard;
use App\Admin\Infrastructure\GameRulesetPublisher;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\CrudDto;
use EasyCorp\Bundle\EasyAdminBundle\Exception\EntityRemoveException;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Throwable;

/** Toute mutation EasyAdmin passe par la publication transactionnelle, jamais par un flush isolé. */
/** @extends AbstractCrudController<object> */
abstract class GameCrudController extends AbstractCrudController
{
    /** Le fichier final réellement promu, pour ne jamais effacer une collision déjà publiée. */
    private ?string $promotedImage = null;

    public function __construct(private readonly GameRulesetPublisher $publisher, private readonly GameConfigurationReferenceGuard $references, protected readonly string $gameImageDirectory, private readonly ?ManagerRegistry $doctrine = null)
    {
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setPaginatorPageSize(30);
    }

    /** @param AdminContext<object> $context */
    public function new(AdminContext $context): KeyValueStore|Response
    {
        try {
            return parent::new($context);
        } catch (BadRequestHttpException $exception) {
            $crud = $context->getCrud();
            \assert($crud instanceof CrudDto);
            $form = $this->createNewForm($context->getEntity(), $crud->getNewFormOptions(), $context);
            $form->handleRequest($context->getRequest());
            $form->addError(new FormError($exception->getMessage()));
            $this->addFlash('danger', $exception->getMessage());

            return $this->configureResponseParameters(KeyValueStore::new([
                'pageName' => Crud::PAGE_NEW,
                'templateName' => 'crud/new',
                'entity' => $context->getEntity(),
                'new_form' => $form,
            ]));
        }
    }

    /** @param AdminContext<object> $context */
    public function edit(AdminContext $context): KeyValueStore|Response
    {
        try {
            return parent::edit($context);
        } catch (BadRequestHttpException $exception) {
            $crud = $context->getCrud();
            \assert($crud instanceof CrudDto);
            $form = $this->createEditForm($context->getEntity(), $crud->getEditFormOptions(), $context);
            $form->handleRequest($context->getRequest());
            $form->addError(new FormError($exception->getMessage()));
            $this->addFlash('danger', $exception->getMessage());

            return $this->configureResponseParameters(KeyValueStore::new([
                'pageName' => Crud::PAGE_EDIT,
                'templateName' => 'crud/edit',
                'edit_form' => $form,
                'entity' => $context->getEntity(),
            ]));
        }
    }

    public function persistEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $this->promotedImage = null;
        try {
            $entityManager->wrapInTransaction(function () use ($entityManager, $entityInstance): void {
                $entityManager->persist($entityInstance);
                $this->finalizeStagedImage($entityInstance);
                $entityManager->flush();
                $this->publisher->publish($entityManager);
            });
            $this->publisher->invalidateAfterCommit();
            $this->promotedImage = null;
        } catch (InvalidArgumentException|LogicException|DbalException $exception) {
            $this->compensateImage($entityInstance, null);
            // Retourner ferait croire à EasyAdmin que l'écriture a réussi et déclencherait
            // l'événement post-persisté sur une transaction annulée.
            throw new BadRequestHttpException($this->formError($exception), $exception);
        } catch (Throwable $exception) {
            $this->compensateImage($entityInstance, null);
            throw $exception;
        }
    }

    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $this->promotedImage = null;
        $original = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance);
        $oldImage = $original['imagePath'] ?? null;
        \assert(null === $oldImage || \is_string($oldImage));
        try {
            $entityManager->wrapInTransaction(function () use ($entityManager, $entityInstance): void {
                $this->finalizeStagedImage($entityInstance);
                $entityManager->flush();
                $this->publisher->publish($entityManager);
            });
            $this->publisher->invalidateAfterCommit();
            $this->promotedImage = null;
        } catch (InvalidArgumentException|LogicException|DbalException $exception) {
            $this->compensateImage($entityInstance, $oldImage);
            throw new BadRequestHttpException($this->formError($exception), $exception);
        } catch (Throwable $exception) {
            $this->compensateImage($entityInstance, $oldImage);
            throw $exception;
        }
    }

    public function deleteEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        try {
            $entityManager->wrapInTransaction(function () use ($entityManager, $entityInstance): void {
                $this->references->assertDeletable($entityInstance);
                $entityManager->remove($entityInstance);
                $entityManager->flush();
                $this->publisher->publish($entityManager);
            });
            $this->publisher->invalidateAfterCommit();
        } catch (InvalidArgumentException|LogicException|DbalException $exception) {
            throw new EntityRemoveException(['entity_name' => $entityInstance::class, 'message' => $exception->getMessage()], $exception);
        }
    }

    /**
     * Active ou désactive une rencontre/coffre et sa table dans une seule publication.
     *
     * Les deux lignes restent éditables séparément pendant leur préparation inactive ; cette
     * action est la seule frontière qui les fait traverser ensemble, sans ouvrir une révision
     * intermédiaire que le runtime refuserait à juste titre.
     */
    /** @param AdminContext<object> $context */
    #[AdminRoute(path: '/{entityId}/toggle-loot-pair', name: 'toggle_loot_pair')]
    public function toggleLootPair(AdminContext $context): Response
    {
        $entity = $context->getEntity()->getInstance();
        if (!$entity instanceof GameEnemy && !$entity instanceof GameItem && !$entity instanceof GameLootTable) {
            throw new LogicException('Cette action ne peut modifier qu’une paire de configuration de jeu.');
        }
        $redirect = $context->getRequest()->headers->get('referer', '/admin');
        \assert(\is_string($redirect));

        try {
            $target = $this->lootPairFor($entity);
            $manager = $this->managerFor($entity);
            $manager->wrapInTransaction(function () use ($manager, $entity, $target): void {
                if ($entity->isActive() !== $target->isActive()) {
                    throw new LogicException('La paire est incohérente : préparez les deux entrées inactives avant de la publier.');
                }
                $active = !$entity->isActive();
                $entity->setActive($active);
                $target->setActive($active);
                $manager->flush();
                $this->publisher->publish($manager);
            });
            $this->publisher->invalidateAfterCommit();
            $this->addFlash('success', $entity->isActive() ? 'La paire de loot est activée.' : 'La paire de loot est désactivée.');
        } catch (InvalidArgumentException|LogicException|DbalException $exception) {
            $this->addFlash('danger', $this->formError($exception));
        }

        return $this->redirect($redirect);
    }

    /** Le staging n'est jamais servi ; une annulation ne peut donc pas publier d'image orpheline. */
    protected function stagingImageDirectory(): string
    {
        $directory = $this->gameImageDirectory.\DIRECTORY_SEPARATOR.'.staging';
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new LogicException('Impossible de préparer le répertoire temporaire des images.');
        }

        return $directory;
    }

    /** Déplace l'upload hors de la zone publique seulement quand l'écriture va être publiée. */
    protected function finalizeStagedImage(object $entity): void
    {
        if (!$entity instanceof GameItem || 'placeholder.png' === $entity->getImagePath()) {
            return;
        }
        $name = $entity->getImagePath();
        if (!preg_match('/^[a-f0-9]{40}(?:-[0-9]+|-[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})?\.(?:jpg|jpeg|png|webp)$/', $name)) {
            throw new LogicException('Le nom de l’image envoyée est invalide.');
        }
        $staged = $this->stagingImageDirectory().\DIRECTORY_SEPARATOR.$name;
        if (!is_file($staged)) {
            return;
        }
        $final = $this->gameImageDirectory.\DIRECTORY_SEPARATOR.$name;
        // Le hash de contenu peut naturellement désigner un fichier déjà publié. La collision
        // est alors le même binaire attendu ; conserver l'ancien chemin protège ses snapshots.
        if (is_file($final)) {
            unlink($staged);

            return;
        }
        if (!rename($staged, $final)) {
            throw new LogicException('Impossible de publier l’image envoyée.');
        }
        $this->promotedImage = $name;
    }

    /** Retire seulement le nouveau fichier déplacé par EasyAdmin avant notre transaction. */
    protected function compensateImage(object $entity, ?string $previousPath): void
    {
        if (!$entity instanceof GameItem || $entity->getImagePath() === $previousPath || $entity->getImagePath() !== $this->promotedImage) {
            return;
        }
        $name = $entity->getImagePath();
        if ('placeholder.png' === $name || basename($name) !== $name) {
            return;
        }
        $path = $this->gameImageDirectory.\DIRECTORY_SEPARATOR.$name;
        if (is_file($path)) {
            unlink($path);
        }
        $this->promotedImage = null;
    }

    /** Les contraintes SQL restent une défense finale, mais l’admin doit les comprendre. */
    private function formError(Throwable $exception): string
    {
        if (!$exception instanceof DbalException) {
            return $exception->getMessage();
        }
        $message = $exception->getMessage();

        return match (true) {
            str_contains($message, 'price_coins') => 'Configuration invalide : le prix en pièces ne peut pas être négatif.',
            str_contains($message, 'coins_minimum'), str_contains($message, 'coins_maximum') => 'Configuration invalide : les pièces de loot doivent respecter leurs bornes.',
            str_contains($message, 'sort_order') => 'Configuration invalide : l’ordre doit être unique dans cette configuration.',
            default => 'Configuration invalide : la modification viole une contrainte de jeu.',
        };
    }

    private function lootPairFor(object $entity): GameEnemy|GameItem|GameLootTable
    {
        $manager = $this->managerFor($entity);
        $repository = $manager->getRepository(GameLootTable::class);

        if ($entity instanceof GameEnemy) {
            $table = $repository->findOneBy(['kind' => 'adversary', 'key' => $entity->getKey()]);
        } elseif ($entity instanceof GameItem && 'CHEST' === $entity->getKind()) {
            $table = $repository->findOneBy(['kind' => 'chest', 'key' => $entity->getKey()]);
        } elseif ($entity instanceof GameLootTable && 'adversary' === $entity->getKind()) {
            $table = $manager->getRepository(GameEnemy::class)->findOneBy(['key' => $entity->getKey()]);
        } elseif ($entity instanceof GameLootTable && 'chest' === $entity->getKind()) {
            $table = $manager->getRepository(GameItem::class)->findOneBy(['key' => $entity->getKey()]);
        } else {
            throw new LogicException('Cette configuration ne forme pas une paire rencontre/coffre avec une table de loot.');
        }

        if (!$table instanceof GameEnemy && !$table instanceof GameItem && !$table instanceof GameLootTable) {
            throw new LogicException('La paire de loot requise est absente : créez et préparez son autre entrée inactive.');
        }

        return $table;
    }

    private function managerFor(object $entity): EntityManagerInterface
    {
        if (!$this->doctrine instanceof ManagerRegistry) {
            throw new LogicException('Le gestionnaire Doctrine est indisponible pour cette action d’administration.');
        }
        $manager = $this->doctrine->getManagerForClass($entity::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException('Le gestionnaire Doctrine de cette configuration est indisponible.');
        }

        return $manager;
    }
}
