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

    public function testExistingContentHashWinsCollisionAndIsNeverCompensatedAway(): void
    {
        $directory = $this->temporaryDirectory();
        try {
            $controller = $this->controller($directory);
            $name = str_repeat('c', 40).'.png';
            file_put_contents($directory.\DIRECTORY_SEPARATOR.$name, 'published-before');
            file_put_contents($controller->staging().\DIRECTORY_SEPARATOR.$name, 'same-content-upload');
            $item = new GameItem();
            $item->setImagePath($name);

            $controller->publishImage($item);
            $controller->compensate($item, 'placeholder.png');

            self::assertSame('published-before', file_get_contents($directory.\DIRECTORY_SEPARATOR.$name));
            self::assertFileDoesNotExist($controller->staging().\DIRECTORY_SEPARATOR.$name);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testConcurrentUploadsWithTheSameContentCannotCompensateEachOther(): void
    {
        $directory = $this->temporaryDirectory();
        try {
            $first = $this->controller($directory);
            $second = $this->controller($directory);
            $hash = str_repeat('d', 40);
            $firstName = $hash.'-11111111-1111-4111-8111-111111111111.png';
            $secondName = $hash.'-22222222-2222-4222-8222-222222222222.png';
            file_put_contents($first->staging().\DIRECTORY_SEPARATOR.$firstName, 'first');
            file_put_contents($second->staging().\DIRECTORY_SEPARATOR.$secondName, 'second');
            $firstItem = new GameItem();
            $firstItem->setImagePath($firstName);
            $secondItem = new GameItem();
            $secondItem->setImagePath($secondName);

            $first->publishImage($firstItem);
            $second->publishImage($secondItem);
            // A échoue après sa promotion alors que B vient de committer : seul A est retiré.
            $first->compensate($firstItem, 'placeholder.png');

            self::assertFileDoesNotExist($directory.\DIRECTORY_SEPARATOR.$firstName);
            self::assertSame('second', file_get_contents($directory.\DIRECTORY_SEPARATOR.$secondName));
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
