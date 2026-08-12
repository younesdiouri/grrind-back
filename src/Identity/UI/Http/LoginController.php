<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use LogicException;
use Nelmio\ApiDocBundle\Attribute\Security;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le login n'a pas de code : `json_login` le traite entièrement (security.yaml), et
 * `AuthenticationResponseListener` compose la réponse.
 *
 * La route existe quand même parce que le routeur passe *avant* le firewall (priorité
 * 32 contre 8) : sans elle, la requête finirait en 404 avant que l'authenticator ne la
 * voie. C'est le montage documenté par Symfony.
 */
final readonly class LoginController
{
    #[Route('/api/auth/login', name: 'identity_login', methods: ['POST'])]
    #[OA\Tag(name: 'Authentification')]
    #[Security(name: null)]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['email', 'password'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email'),
                new OA\Property(property: 'password', type: 'string', format: 'password'),
            ],
        ),
    )]
    #[OA\Response(
        response: 200,
        description: 'Identifiants acceptés.',
        content: new OA\JsonContent(ref: '#/components/schemas/AuthSession'),
    )]
    #[OA\Response(
        response: 401,
        description: 'Identifiants refusés. La réponse ne distingue pas une adresse inconnue d\'un mot de passe faux, et le temps de réponse non plus (#39).',
        content: new OA\MediaType(
            mediaType: 'application/problem+json',
            schema: new OA\Schema(ref: '#/components/schemas/ProblemDetails'),
        ),
    )]
    public function __invoke(): JsonResponse
    {
        throw new LogicException('Cette route est interceptée par le firewall « login » : le contrôleur ne doit jamais être atteint.');
    }
}
