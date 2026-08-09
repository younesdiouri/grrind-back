<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Email;
use App\Identity\Domain\Exception\InvalidCredentials;
use App\Identity\Domain\PasswordHasher;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use InvalidArgumentException;

final readonly class LogInHandler
{
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $passwordHasher,
        private IssueTokens $issueTokens,
    ) {
    }

    /**
     * @throws InvalidCredentials
     */
    public function __invoke(LogIn $command): AuthenticatedUser
    {
        $user = $this->findUser($command->email);

        if (null === $user) {
            // Sans ce hachage à vide, une adresse inconnue répondrait en une
            // fraction du temps d'une adresse connue : le login deviendrait un
            // moyen fiable d'énumérer les comptes.
            $this->passwordHasher->hash($command->plainPassword);

            throw new InvalidCredentials();
        }

        if (!$this->passwordHasher->verify($user->passwordHash(), $command->plainPassword)) {
            throw new InvalidCredentials();
        }

        if ($this->passwordHasher->needsRehash($user->passwordHash())) {
            // Le mot de passe en clair ne repassera pas de sitôt : c'est la seule
            // occasion de rattraper un hash produit par un algorithme dépassé.
            $user->changePasswordHash($this->passwordHasher->hash($command->plainPassword));
            $this->users->commit();
        }

        return new AuthenticatedUser($user, ($this->issueTokens)($user));
    }

    private function findUser(string $email): ?User
    {
        try {
            return $this->users->ofEmail(Email::fromString($email));
        } catch (InvalidArgumentException) {
            // Adresse syntaxiquement invalide : aucun compte ne peut correspondre.
            return null;
        }
    }
}
