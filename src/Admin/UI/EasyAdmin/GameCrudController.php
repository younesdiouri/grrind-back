<?php

declare(strict_types=1);

namespace App\Admin\UI\EasyAdmin;

use App\Admin\Domain\GameItem;
use App\Admin\Infrastructure\GameConfigurationReferenceGuard;
use App\Admin\Infrastructure\GameRulesetPublisher;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use InvalidArgumentException;
use LogicException;
use Throwable;

/** Toute mutation EasyAdmin passe par la publication transactionnelle, jamais par un flush isolé. */
/** @extends AbstractCrudController<object> */
abstract class GameCrudController extends AbstractCrudController
{
    public function __construct(private readonly GameRulesetPublisher $publisher, private readonly GameConfigurationReferenceGuard $references, protected readonly string $gameImageDirectory)
    {
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setPaginatorPageSize(30);
    }

    public function persistEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        try {
            $entityManager->wrapInTransaction(function () use ($entityManager, $entityInstance): void {
                $entityManager->persist($entityInstance);
                $entityManager->flush();
                $this->publisher->publish($entityManager);
            });
            $this->publisher->invalidateAfterCommit();
        } catch (InvalidArgumentException|LogicException $exception) {
            $this->compensateImage($entityInstance, null);
            $this->addFlash('danger', $exception->getMessage());
        } catch (Throwable $exception) {
            $this->compensateImage($entityInstance, null);
            throw $exception;
        }
    }

    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $original = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance);
        $oldImage = $original['imagePath'] ?? null;
        \assert(null === $oldImage || \is_string($oldImage));
        try {
            $entityManager->wrapInTransaction(function () use ($entityManager): void {
                $entityManager->flush();
                $this->publisher->publish($entityManager);
            });
            $this->publisher->invalidateAfterCommit();
        } catch (InvalidArgumentException|LogicException $exception) {
            $this->compensateImage($entityInstance, $oldImage);
            $this->addFlash('danger', $exception->getMessage());
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
        } catch (InvalidArgumentException|LogicException $exception) {
            $this->addFlash('danger', $exception->getMessage());
        }
    }

    /** Retire seulement le nouveau fichier déplacé par EasyAdmin avant notre transaction. */
    private function compensateImage(object $entity, ?string $previousPath): void
    {
        if (!$entity instanceof GameItem || $entity->getImagePath() === $previousPath) {
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
    }
}
