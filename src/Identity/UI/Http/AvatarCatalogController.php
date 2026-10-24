<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use App\Shared\Domain\Avatar;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AvatarCatalogController
{
    #[Route('/api/avatar-catalog', name: 'identity_avatar_catalog', methods: ['GET'])]
    #[OA\Tag(name: 'Profil')]
    #[OA\Response(response: 200, description: 'Options Unity compatibles et défaut de la version courante.', content: new OA\JsonContent(ref: '#/components/schemas/AvatarCatalog'))]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(Avatar::catalog());
    }
}
