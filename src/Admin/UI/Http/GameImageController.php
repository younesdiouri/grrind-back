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

    #[Route('/admin/images/{name}', name: 'admin_game_image', methods: ['GET'], requirements: ['name' => '(?:[a-f0-9]{64}|placeholder)\\.(?:jpg|jpeg|png|webp)'])]
    public function __invoke(string $name): Response
    {
        $path = $this->gameImageDirectory.\DIRECTORY_SEPARATOR.$name;
        if (!is_file($path)) {
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
