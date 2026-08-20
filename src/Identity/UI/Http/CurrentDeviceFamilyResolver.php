<?php

declare(strict_types=1);

namespace App\Identity\UI\Http;

use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsTargetedValueResolver;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Lit le claim `fid` du jeton d'accès courant — la famille de refresh tokens dont il est né.
 *
 * `JWTTokenManagerInterface::decode()` est le chemin documenté par Lexik pour accéder au
 * jeton authentifié : le provider de `security.yaml` (`app_users`, un `entity` Doctrine) n'est
 * pas un `PayloadAwareUserProviderInterface`, donc le payload décodé par l'authenticator
 * n'atteint jamais l'objet `User`. Redécoder le jeton courant depuis le `TokenStorageInterface`
 * est le seul chemin qui reste.
 *
 * **Ne sert qu'à retrouver un appareil, jamais à authentifier.** Un JWT ne se révoque pas —
 * c'est pour ça qu'il dure quinze minutes — et ce resolver ne vérifie jamais `fid` contre
 * l'état des familles en base : ce serait défaire cette propriété à chaque requête. Voir
 * {@see CurrentDeviceFamily} pour pourquoi une famille absente rend `null` plutôt qu'une
 * erreur.
 *
 * @see https://symfony.com/doc/current/controller/value_resolver.html
 */
#[AsTargetedValueResolver]
final readonly class CurrentDeviceFamilyResolver implements ValueResolverInterface
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private JWTTokenManagerInterface $jwt,
    ) {
    }

    /**
     * @return iterable<?Uuid>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if (Uuid::class !== $argument->getType()) {
            return [];
        }

        $token = $this->tokenStorage->getToken();

        if (null === $token) {
            return [null];
        }

        $payload = $this->jwt->decode($token);
        $fid = false !== $payload ? ($payload['fid'] ?? null) : null;

        if (!\is_string($fid) || !Uuid::isValid($fid)) {
            return [null];
        }

        return [Uuid::fromString($fid)];
    }
}
