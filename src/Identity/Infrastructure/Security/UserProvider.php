<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Domain\UserRepository;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @implements UserProviderInterface<SecurityUser>
 */
final readonly class UserProvider implements UserProviderInterface
{
    public function __construct(private UserRepository $users)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        // L'identifiant vient du `sub` d'un JWT : il peut être n'importe quoi.
        if (!Uuid::isValid($identifier)) {
            throw new UserNotFoundException();
        }

        $user = $this->users->ofId(Uuid::fromString($identifier));

        if (null === $user) {
            // Compte supprimé alors qu'un jeton encore valide circule : c'est le
            // scénario normal d'un JWT, pas une anomalie.
            throw new UserNotFoundException();
        }

        return new SecurityUser($user->id()->toRfc4122(), $user->passwordHash());
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof SecurityUser) {
            throw new UnsupportedUserException(\sprintf('Utilisateur non géré : "%s".', $user::class));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return SecurityUser::class === $class;
    }
}
