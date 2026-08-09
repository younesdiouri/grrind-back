<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Domain\PasswordHasher;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * L'algorithme et son coût sont pilotés par `security.password_hashers` — donc
 * réduits en test, où un argon2id complet coûterait des secondes par cas.
 */
#[AsAlias(PasswordHasher::class)]
final readonly class SymfonyPasswordHasher implements PasswordHasher
{
    public function __construct(private PasswordHasherFactoryInterface $factory)
    {
    }

    public function hash(string $plainPassword): string
    {
        return $this->factory->getPasswordHasher(SecurityUser::class)->hash($plainPassword);
    }

    public function verify(string $hash, string $plainPassword): bool
    {
        return $this->factory->getPasswordHasher(SecurityUser::class)->verify($hash, $plainPassword);
    }

    public function needsRehash(string $hash): bool
    {
        return $this->factory->getPasswordHasher(SecurityUser::class)->needsRehash($hash);
    }
}
