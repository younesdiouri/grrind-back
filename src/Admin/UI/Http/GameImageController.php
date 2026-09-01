<?php

declare(strict_types=1);

namespace App\Admin\UI\Http;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Les noms sont générés par le serveur et la route n'accepte jamais de segment de chemin. */
final readonly class GameImageController
{
    public function __construct(private string $gameImageDirectory)
    {
    }

    #[Route('/game-images/{name}', name: 'game_image', methods: ['GET'], requirements: ['name' => '(?:[a-f0-9]{40}(?:-[0-9]+)?|placeholder)\\.(?:jpg|jpeg|png|webp)'])]
    public function __invoke(string $name): Response
    {
        $path = $this->gameImageDirectory.\DIRECTORY_SEPARATOR.$name;
        if (!is_file($path)) {
            if ('placeholder.png' === $name) {
                // Le volume Fly est vide à sa première création : la route reste atteignable
                // avant le provisionnement documenté, sans écrire un effet filesystem hors
                // transaction dans une migration.
                $placeholder = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL8ywAAAABJRU5ErkJggg==', true);
                \assert(false !== $placeholder);

                return new Response($placeholder, Response::HTTP_OK, [
                    'Content-Type' => 'image/png',
                    'Cache-Control' => 'public, max-age=300',
                ]);
            }
            throw new NotFoundHttpException();
        }

        $type = match (pathinfo($path, \PATHINFO_EXTENSION)) {
            'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', default => throw new NotFoundHttpException(),
        };
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $type);
        $response->setPublic();
        $response->setMaxAge(31536000);
        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');

        return $response;
    }
}
