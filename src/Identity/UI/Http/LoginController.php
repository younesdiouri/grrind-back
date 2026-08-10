<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use LogicException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le login n'a pas de code : il est entièrement traité par l'authenticator
 * `json_login` du firewall (voir config/packages/security.yaml). Vérification du
 * mot de passe, protection contre l'énumération, rehash opportuniste et réponse
 * d'erreur viennent du composant Security — c'est exactement ce qu'on ne veut
 * pas réécrire.
 *
 * La route existe quand même parce que le routeur passe *avant* le firewall
 * (priorité 32 contre 8) : sans elle, la requête finirait en 404 avant que
 * l'authenticator ne la voie. C'est le montage documenté par Symfony.
 *
 * La réponse en cas de succès est composée par `AuthenticationResponseListener`.
 */
final readonly class LoginController
{
    #[Route('/api/auth/login', name: 'identity_login', methods: ['POST'])]
    public function __invoke(): JsonResponse
    {
        throw new LogicException('Cette route est interceptée par le firewall « login » : le contrôleur ne doit jamais être atteint.');
    }
}
