<?php

declare(strict_types=1);

namespace App\Admin\UI\Http;

use App\Shared\Application\ArtworkCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[IsGranted('ROLE_ADMIN')]
final class ArtworkOverviewController extends AbstractController
{
    public function __construct(private readonly string $gameImageDirectory)
    {
    }

    #[Route('/admin/artwork', name: 'admin_artwork_overview', methods: ['GET'])]
    public function __invoke(ArtworkCatalog $catalog): Response
    {
        return $this->render('admin/artwork.html.twig', [
            'catalogJson' => json_encode($catalog->all(), \JSON_THROW_ON_ERROR | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT),
        ]);
    }

    #[Route('/admin/artwork/upload', name: 'admin_artwork_upload', methods: ['POST'])]
    public function upload(Request $request, ValidatorInterface $validator): JsonResponse
    {
        if (!$this->isCsrfTokenValid('artwork_upload', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $file = $request->files->get('image');
        if (!$file instanceof UploadedFile || !$file->isValid()
            || 0 !== $validator->validate($file, new Image(maxSize: '2M', mimeTypes: ['image/png'], maxWidth: 2048, maxHeight: 2048, detectCorrupted: true))->count()) {
            return new JsonResponse(['error' => 'PNG valide requis : 2 Mo et 2048 px maximum.'], 422);
        }
        $hash = hash_file('sha1', $file->getPathname());
        \assert(\is_string($hash));
        $name = $hash.'.png';
        new Filesystem()->mkdir($this->gameImageDirectory);
        if (!is_file($this->gameImageDirectory.'/'.$name)) {
            $file->move($this->gameImageDirectory, $name);
        }

        return new JsonResponse(['path' => '/game-images/'.$name], 201);
    }
}
