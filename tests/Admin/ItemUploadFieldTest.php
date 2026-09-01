<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Infrastructure\GameConfigurationReferenceGuard;
use App\Admin\Infrastructure\GameRulesetPublisher;
use App\Admin\UI\EasyAdmin\ItemCrudController;
use Doctrine\DBAL\Connection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Validation;

/** L'accept HTML ne suffit pas : les contraintes EasyAdmin sont prouvées sur le contenu reçu. */
final class ItemUploadFieldTest extends TestCase
{
    public function testNewItemRequiresAnImageButEditingKeepsTheExistingOne(): void
    {
        $controller = new ItemCrudController(new GameRulesetPublisher(new TagAwareAdapter(new ArrayAdapter()), 'v1'), new GameConfigurationReferenceGuard($this->createStub(Connection::class)), sys_get_temp_dir());

        self::assertTrue($this->imageField($controller, Crud::PAGE_NEW)->getAsDto()->getFormTypeOption('required'));
        self::assertFalse($this->imageField($controller, Crud::PAGE_EDIT)->getAsDto()->getFormTypeOption('required'));
    }

    public function testForgedMimeSvgOversizeAndOversizedDimensionsAreRejectedByTheActualFieldConstraint(): void
    {
        $controller = new ItemCrudController(new GameRulesetPublisher(new TagAwareAdapter(new ArrayAdapter()), 'v1'), new GameConfigurationReferenceGuard($this->createStub(Connection::class)), sys_get_temp_dir());
        $constraints = $this->imageField($controller, Crud::PAGE_NEW)->getAsDto()->getCustomOption(ImageField::OPTION_FILE_CONSTRAINTS);
        self::assertIsArray($constraints);
        self::assertCount(1, $constraints);
        self::assertInstanceOf(Image::class, $constraints[0]);
        $constraint = $constraints[0];

        $forged = $this->file('forged.png', 'not an image');
        $svg = $this->file('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $oversize = $this->file('large.png', str_repeat('x', (2 * 1024 * 1024) + 1));
        $dimensions = $this->file('wide.png', self::pngWithDimensions(4097, 1));
        try {
            $validator = Validation::createValidator();
            foreach ([$forged, $svg, $oversize, $dimensions] as $upload) {
                self::assertNotCount(0, $validator->validate($upload, $constraint), $upload->getClientOriginalName().' a contourné la validation d’image.');
            }
        } finally {
            foreach ([$forged, $svg, $oversize, $dimensions] as $upload) {
                @unlink($upload->getPathname());
            }
        }
    }

    private function imageField(ItemCrudController $controller, string $page): ImageField
    {
        foreach ($controller->configureFields($page) as $field) {
            if ($field instanceof ImageField) {
                return $field;
            }
        }

        self::fail('Le formulaire item doit porter un champ ImageField.');
    }

    private function file(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'grrind-upload-');
        self::assertIsString($path);
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, 'image/png', test: true);
    }

    private static function pngWithDimensions(int $width, int $height): string
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL8ywAAAABJRU5ErkJggg==', true);
        self::assertIsString($png);
        $header = substr($png, 12, 13);
        $header = pack('N', $width).pack('N', $height).substr($header, 8);

        return substr($png, 0, 12).$header.pack('N', hexdec(hash('crc32b', 'IHDR'.$header))).substr($png, 29);
    }
}
