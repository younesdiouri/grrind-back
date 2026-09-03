<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Exception\EmailAlreadyUsed;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Doctrine\UserRepository;
use App\Shared\Domain\Timezone;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Les handlers sont appelés directement par les contrôleurs : pas de bus de
 * commandes tant qu'il n'y a rien à découpler. Messenger arrivera pour l'asynchrone
 * (classements, notifications), pas pour ajouter une indirection synchrone.
 */
final readonly class RegisterUserHandler
{
    public function __construct(
        private UserRepository $users,
        private UserPasswordHasherInterface $passwordHasher,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws EmailAlreadyUsed
     */
    public function __invoke(RegisterUser $command): User
    {
        // Vérification de confort : elle donne un 409 lisible dans le cas courant.
        // La garantie, elle, vient de l'index unique — voir UserRepository::add().
        if ($this->users->emailExists($command->email)) {
            throw new EmailAlreadyUsed($command->email);
        }

        $user = User::register(
            $command->email,
            $command->displayName,
            Timezone::fromString($command->timezone),
            $this->clock->now(),
            $command->locale,
        );

        // L'algorithme et son coût viennent de `security.password_hashers`, donc
        // réduits en test où un argon2id complet coûterait des secondes par cas.
        $user->setPassword($this->passwordHasher->hashPassword($user, $command->plainPassword));

        $this->users->add($user);

        return $user;
    }
}
