<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Domain\AccessTokenIssuer;
use App\Identity\Domain\User;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(AccessTokenIssuer::class)]
final readonly class LexikAccessTokenIssuer implements AccessTokenIssuer
{
    public function __construct(
        private JWTTokenManagerInterface $tokenManager,
        private int $accessTokenTtl,
    ) {
    }

    public function issueFor(User $user): string
    {
        // Le hash n'a rien à faire dans un jeton : SecurityUser ne sert ici qu'à
        // porter l'identifiant jusqu'au manager.
        return $this->tokenManager->create(new SecurityUser($user->id()->toRfc4122(), ''));
    }

    public function lifetimeInSeconds(): int
    {
        return $this->accessTokenTtl;
    }
}
