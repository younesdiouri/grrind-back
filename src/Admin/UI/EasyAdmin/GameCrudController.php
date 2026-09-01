<?php

declare(strict_types=1);

namespace App\Admin\UI\EasyAdmin;

use App\Admin\Domain\GameItem;
use App\Admin\Infrastructure\GameConfigurationReferenceGuard;
use App\Admin\Infrastructure\GameRulesetPublisher;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Exception\EntityRemoveException;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
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
                $this->finalizeStagedImage($entityInstance);
                $entityManager->flush();
                $this->publisher->publish($entityManager);
            });
            $this->publisher->invalidateAfterCommit();
        } catch (InvalidArgumentException|LogicException $exception) {
            $this->compensateImage($entityInstance, null);
            // Retourner ferait croire à EasyAdmin que l'écriture a réussi et déclencherait
            // l'événement post-persisté sur une transaction annulée.
            throw new BadRequestHttpException($exception->getMessage(), $exception);
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
            $entityManager->wrapInTransaction(function () use ($entityManager, $entityInstance): void {
                $this->finalizeStagedImage($entityInstance);
                $entityManager->flush();
                $this->publisher->publish($entityManager);
            });
            $this->publisher->invalidateAfterCommit();
        } catch (InvalidArgumentException|LogicException $exception) {
            $this->compensateImage($entityInstance, $oldImage);
            throw new BadRequestHttpException($exception->getMessage(), $exception);
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
            throw new EntityRemoveException(['entity_name' => $entityInstance::class, 'message' => $exception->getMessage()], $exception);
        }
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
    private function finalizeStagedImage(object $entity): void
    {
        if (!$entity instanceof GameItem || 'placeholder.png' === $entity->getImagePath()) {
            return;
        }
        $name = $entity->getImagePath();
        if (!preg_match('/^[a-f0-9]{40}(?:-[0-9]+)?\.(?:jpg|jpeg|png|webp)$/', $name)) {
            throw new LogicException('Le nom de l’image envoyée est invalide.');
        }
        $staged = $this->stagingImageDirectory().\DIRECTORY_SEPARATOR.$name;
        if (!is_file($staged)) {
            return;
        }
        $final = $this->gameImageDirectory.\DIRECTORY_SEPARATOR.$name;
        if (!rename($staged, $final)) {
            throw new LogicException('Impossible de publier l’image envoyée.');
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
