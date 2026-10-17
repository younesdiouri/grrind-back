<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Shared\Application\ArtworkCatalog;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** Les poses et calques sont administrables et indépendants de l’équilibrage. */
final readonly class AppearanceController
{
    public function __construct(private ArtworkCatalog $artwork)
    {
    }

    #[Route('/api/appearances', name: 'identity_appearances', methods: ['GET'])]
    #[OA\Tag(name: 'Profil')]
    #[OA\Response(response: 200, description: 'Avatars, poses, ancrages et équipements graphiques.', content: new OA\JsonContent(ref: '#/components/schemas/AppearanceCatalog'))]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse($this->artwork->all());
    }
}
