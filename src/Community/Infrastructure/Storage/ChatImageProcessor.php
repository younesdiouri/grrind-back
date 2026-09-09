<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Storage;

use App\Community\Application\PreparedChatImage;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Validator vérifie les limites avant que GD alloue les pixels. Réencoder retire EXIF,
 * GPS et toute charge jointe au fichier : le client ne peut pas publier ses octets bruts.
 * Le WebP de sortie est lui aussi borné pour ne pas déplacer l'abus après le décodage.
 *
 * @see https://symfony.com/doc/current/reference/constraints/Image.html
 */
final readonly class ChatImageProcessor
{
    public function __construct(private ValidatorInterface $validator, private int $chatImageMaxBytes, private int $chatImageMaxPixels)
    {
    }

    public function prepare(UploadedFile $upload): PreparedChatImage
    {
        if ($this->chatImageMaxBytes < 1 || $this->chatImageMaxPixels < 1) {
            throw new LogicException('Les limites des images doivent être positives.');
        }
        $violations = $this->validator->validate($upload, new Image(maxSize: $this->chatImageMaxBytes, mimeTypes: ['image/jpeg', 'image/png', 'image/webp'], maxPixels: $this->chatImageMaxPixels));
        if (\count($violations) > 0) {
            throw new ValidationFailedException($upload, $violations);
        }
        $contents = $upload->getContent();
        $image = @imagecreatefromstring($contents);
        if (false === $image) {
            throw new UnprocessableEntityHttpException('Cette image est corrompue.');
        }
        // L'orientation des photos téléphone doit survivre au retrait de leur EXIF.
        if ('image/jpeg' === $upload->getMimeType()) {
            $exif = @exif_read_data($upload->getPathname());
            $orientation = false === $exif ? 1 : ($exif['Orientation'] ?? 1);
            if (\in_array($orientation, [2, 5, 7], true)) {
                imageflip($image, \IMG_FLIP_HORIZONTAL);
            } elseif (4 === $orientation) {
                imageflip($image, \IMG_FLIP_VERTICAL);
            }
            $angle = match ($orientation) {
                3 => 180, 5, 8 => 90, 6, 7 => -90, default => 0
            };
            if (0 !== $angle) {
                $rotated = imagerotate($image, $angle, 0);
                if (false === $rotated) {
                    throw new UnprocessableEntityHttpException('Cette image ne peut pas être orientée.');
                }
                $image = $rotated;
            }
        }
        $stream = fopen('php://temp', 'w+');
        if (false === $stream) {
            throw new RuntimeException('Impossible de préparer la pièce jointe.');
        }
        try {
            if (!imagewebp($image, $stream, 85)) {
                throw new UnprocessableEntityHttpException('Cette image ne peut pas être réencodée.');
            }
            rewind($stream);
            $encoded = stream_get_contents($stream);
            if (false === $encoded || '' === $encoded || \strlen($encoded) > $this->chatImageMaxBytes) {
                throw new UnprocessableEntityHttpException('Image réencodée trop volumineuse.');
            }

            return new PreparedChatImage($encoded, hash('sha256', $contents));
        } finally {
            fclose($stream);
        }
    }
}
