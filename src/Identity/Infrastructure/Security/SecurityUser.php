<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Adaptateur entre le composant Security et le domaine. L'entité `User` n'implémente
 * volontairement pas `UserInterface` : le domaine n'a pas à connaître les rôles
 * Symfony, ni `eraseCredentials()`, ni la notion d'identifiant de firewall.
 *
 * L'identifiant est l'UUID, pas l'e-mail : changer d'adresse ne doit invalider
 * aucun jeton, et l'e-mail n'a rien à faire dans le payload d'un JWT.
 */
final class SecurityUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    /**
     * @param non-empty-string $id
     */
    public function __construct(
        private readonly string $id,
        private readonly string $passwordHash,
    ) {
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        return $this->id;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    /**
     * Déprécié depuis Symfony 7.3 et sans objet ici : l'objet est reconstruit à
     * chaque requête, il n'est jamais mis en session.
     */
    public function eraseCredentials(): void
    {
    }
}
