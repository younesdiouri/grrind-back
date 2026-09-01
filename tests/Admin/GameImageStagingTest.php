<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Domain\GameItem;
use App\Admin\Infrastructure\GameConfigurationReferenceGuard;
use App\Admin\Infrastructure\GameRulesetPublisher;
use App\Admin\UI\EasyAdmin\GameCrudController;
use Doctrine\DBAL\Connection;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

final class GameImageStagingTest extends TestCase
{
    public function testStagedReplacementOnlyBecomesPublicDuringPublicationAndCanBeCompensated(): void
    {
        $directory = $this->temporaryDirectory();
        try {
            $controller = $this->controller($directory);
            $old = str_repeat('a', 40).'.png';
            $new = str_repeat('b', 40).'.png';
            file_put_contents($directory.\DIRECTORY_SEPARATOR.$old, 'old');
            $staging = $controller->staging();
            file_put_contents($staging.\DIRECTORY_SEPARATOR.$new, 'new');
            $item = new GameItem();
            $item->setImagePath($new);

            $controller->publishImage($item);

            self::assertFileDoesNotExist($staging.\DIRECTORY_SEPARATOR.$new);
            self::assertFileExists($directory.\DIRECTORY_SEPARATOR.$new);
            self::assertFileExists($directory.\DIRECTORY_SEPARATOR.$old);

            $controller->compensate($item, $old);
            self::assertFileDoesNotExist($directory.\DIRECTORY_SEPARATOR.$new);
            self::assertFileExists($directory.\DIRECTORY_SEPARATOR.$old);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testTraversalCannotBePromotedFromStaging(): void
    {
        $directory = $this->temporaryDirectory();
        try {
            $item = new GameItem();
            $item->setImagePath('../../.env');
            $controller = $this->controller($directory);

            $this->expectException(LogicException::class);
            $controller->publishImage($item);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    private function controller(string $directory): ImageStagingCrudController
    {
        return new ImageStagingCrudController(new GameRulesetPublisher(new TagAwareAdapter(new ArrayAdapter()), 'v1'), new GameConfigurationReferenceGuard($this->createStub(Connection::class)), $directory);
    }

    private function temporaryDirectory(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'grrind-image-');
        self::assertIsString($path);
        unlink($path);
        mkdir($path, 0o775);

        return $path;
    }

    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory.'/{,.staging/}*', \GLOB_BRACE) ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($directory.\DIRECTORY_SEPARATOR.'.staging')) {
            rmdir($directory.\DIRECTORY_SEPARATOR.'.staging');
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}

/** Adaptateur de test : le flux fichier reste prouvé sans simuler EasyAdmin lui-même. */
final class ImageStagingCrudController extends GameCrudController
{
    public static function getEntityFqcn(): string
    {
        return GameItem::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [];
    }

    public function staging(): string
    {
        return $this->stagingImageDirectory();
    }

    public function publishImage(GameItem $item): void
    {
        $this->finalizeStagedImage($item);
    }

    public function compensate(GameItem $item, string $previous): void
    {
        $this->compensateImage($item, $previous);
    }
}
