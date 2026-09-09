<?php

declare(strict_types=1);

namespace App\Tests\Community;

use App\Community\Infrastructure\Storage\ChatImageProcessor;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validation;

final class ChatImageProcessorTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function formats(): iterable
    {
        foreach (['jpeg', 'png', 'webp'] as $format) {
            yield $format => [$format];
        }
    }

    #[DataProvider('formats')]
    public function testReencodesAllowedFormatsAndDropsAppendedMetadata(string $format): void
    {
        $path = $this->image($format);
        file_put_contents($path, 'private-gps-metadata', \FILE_APPEND);
        try {
            $processor = new ChatImageProcessor(Validation::createValidator(), 5242880, 20000000);
            $prepared = $processor->prepare(new UploadedFile($path, 'untrusted-name.txt', 'text/plain', test: true));
            self::assertStringNotContainsString('private-gps-metadata', $prepared->contents);
            $size = getimagesizefromstring($prepared->contents);
            self::assertIsArray($size);
            self::assertSame('image/webp', $size['mime']);
            self::assertSame(hash_file('sha256', $path), $prepared->fingerprint);
        } finally {
            unlink($path);
        }
    }

    public function testRejectsPixelBombBeforeDecoding(): void
    {
        $path = $this->image('png');
        try {
            $this->expectException(ValidationFailedException::class);
            new ChatImageProcessor(Validation::createValidator(), 5242880, 3)->prepare(new UploadedFile($path, 'image.png', test: true));
        } finally {
            unlink($path);
        }
    }

    public function testRejectsExcessiveBytes(): void
    {
        $path = $this->image('png');
        try {
            $this->expectException(ValidationFailedException::class);
            new ChatImageProcessor(Validation::createValidator(), 1, 20000000)->prepare(new UploadedFile($path, 'image.png', test: true));
        } finally {
            unlink($path);
        }
    }

    public function testRejectsFakeImage(): void
    {
        $path = $this->image('png');
        file_put_contents($path, '<script>alert(1)</script>');
        try {
            $this->expectException(ValidationFailedException::class);
            new ChatImageProcessor(Validation::createValidator(), 5242880, 20000000)->prepare(new UploadedFile($path, 'image.png', 'image/png', test: true));
        } finally {
            unlink($path);
        }
    }

    public function testRejectsCorruptPngWithValidDimensions(): void
    {
        $path = $this->image('png');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a5WQAAAAASUVORK5CYII='));
        try {
            $this->expectException(UnprocessableEntityHttpException::class);
            new ChatImageProcessor(Validation::createValidator(), 5242880, 20000000)->prepare(new UploadedFile($path, 'image.png', 'image/png', test: true));
        } finally {
            unlink($path);
        }
    }

    /** @return iterable<string, array{int, int, int, int}> */
    public static function orientations(): iterable
    {
        yield 'normal' => [1, 40, 60, 0xFF0000];
        yield 'miroir horizontal' => [2, 40, 60, 0x00FF00];
        yield 'demi-tour' => [3, 40, 60, 0x0000FF];
        yield 'miroir vertical' => [4, 40, 60, 0xFFFF00];
        yield 'transposée' => [5, 60, 40, 0xFF0000];
        yield 'portrait horaire' => [6, 60, 40, 0xFFFF00];
        yield 'transverse' => [7, 60, 40, 0x0000FF];
        yield 'portrait antihoraire' => [8, 60, 40, 0x00FF00];
    }

    #[DataProvider('orientations')]
    public function testAppliesExifOrientationBeforeRemovingMetadata(int $orientation, int $width, int $height, int $topLeft): void
    {
        $path = tempnam(sys_get_temp_dir(), 'chat-exif-');
        self::assertIsString($path);
        $source = imagecreatetruecolor(40, 60);
        self::assertNotFalse($source);
        imagefilledrectangle($source, 0, 0, 19, 29, 0xFF0000);
        imagefilledrectangle($source, 20, 0, 39, 29, 0x00FF00);
        imagefilledrectangle($source, 0, 30, 19, 59, 0xFFFF00);
        imagefilledrectangle($source, 20, 30, 39, 59, 0x0000FF);
        imagejpeg($source, $path, 100);
        $jpeg = file_get_contents($path);
        self::assertIsString($jpeg);
        $exif = "Exif\0\0II".pack('vV', 42, 8).pack('v', 1).pack('vvV', 0x0112, 3, 1).pack('v', $orientation)."\0\0".pack('V', 0);
        file_put_contents($path, substr($jpeg, 0, 2)."\xff\xe1".pack('n', \strlen($exif) + 2).$exif.substr($jpeg, 2));
        try {
            $prepared = new ChatImageProcessor(Validation::createValidator(), 5242880, 20000000)->prepare(new UploadedFile($path, 'portrait.jpg', test: true));
            $image = imagecreatefromstring($prepared->contents);
            self::assertNotFalse($image);
            self::assertSame($width, imagesx($image));
            self::assertSame($height, imagesy($image));
            $pixel = imagecolorat($image, 5, 5);
            self::assertNotFalse($pixel);
            foreach ([16, 8, 0] as $shift) {
                self::assertEqualsWithDelta(($topLeft >> $shift) & 255, ($pixel >> $shift) & 255, 12);
            }
            self::assertStringNotContainsString('EXIF', $prepared->contents);
        } finally {
            unlink($path);
        }
    }

    private function image(string $format): string
    {
        $path = tempnam(sys_get_temp_dir(), 'chat-image-test-');
        self::assertIsString($path);
        $image = imagecreatetruecolor(2, 2);
        self::assertNotFalse($image);
        match ($format) {
            'jpeg' => imagejpeg($image, $path),
            'png' => imagepng($image, $path),
            'webp' => imagewebp($image, $path),
            default => throw new LogicException(),
        };

        return $path;
    }
}
