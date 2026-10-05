<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Shared\Domain\Appearance;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\UrlHelper;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le catalogue des modèles de héros (#289) : chaque clé que `PATCH /api/me` accepte, avec ses
 * images. Le profil, les combats et Ālam ne portent que la clé ; le client charge ce catalogue
 * une fois et y retrouve les poses de n'importe quel joueur.
 *
 * **De dos** pour le joueur lui-même, **de face** pour qui l'affronte. Les miniatures ont le
 * même cadrage et la même ligne de sol, en 256 px : Ālam en aligne une guilde entière, et vingt
 * jeux de poses décodés en 1024 px pèseraient environ 240 Mo en mémoire.
 */
final readonly class AppearanceController
{
    private const array VIEWS = ['back', 'front'];
    private const array POSES = ['idle', 'attack', 'hit'];

    public function __construct(private UrlHelper $urls)
    {
    }

    #[Route('/api/appearances', name: 'identity_appearances', methods: ['GET'])]
    #[OA\Tag(name: 'Profil')]
    #[OA\Response(response: 200, description: 'Les modèles de héros disponibles et leurs poses.', content: new OA\JsonContent(ref: '#/components/schemas/AppearanceCatalog'))]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['appearances' => array_map($this->model(...), Appearance::cases())]);
    }

    /** @return array<string, mixed> */
    private function model(Appearance $appearance): array
    {
        $images = [];
        $thumbnails = [];
        foreach (self::VIEWS as $view) {
            foreach (self::POSES as $pose) {
                $images[$view][$pose] = $this->url($appearance, "{$view}/{$pose}.png");
                $thumbnails[$view][$pose] = $this->url($appearance, "thumb/{$view}/{$pose}.png");
            }
        }

        return ['key' => $appearance->value, 'imageUrls' => $images, 'thumbnailUrls' => $thumbnails];
    }

    private function url(Appearance $appearance, string $file): string
    {
        return $this->urls->getAbsoluteUrl('/appearances/'.$appearance->directory().'/'.$file);
    }
}
