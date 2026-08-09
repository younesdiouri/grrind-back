<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Email;
use App\Identity\Domain\Exception\EmailAlreadyUsed;
use App\Identity\Domain\PasswordHasher;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Shared\Domain\Timezone;
use Psr\Clock\ClockInterface;

/**
 * Les handlers sont appelés directement par les contrôleurs : pas de bus de
 * commandes tant qu'il n'y a rien à découpler. Messenger arrivera pour l'asynchrone
 * (classements, notifications), pas pour ajouter une indirection synchrone.
 */
final readonly class RegisterUserHandler
{
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $passwordHasher,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws EmailAlreadyUsed
     */
    public function __invoke(RegisterUser $command): User
    {
        $email = Email::fromString($command->email);

        // Vérification de confort : elle donne un 409 lisible dans le cas courant.
        // La garantie, elle, vient de l'index unique — voir UserRepository::add().
        if ($this->users->emailExists($email)) {
            throw new EmailAlreadyUsed($email);
        }

        $user = User::register(
            $email,
            $this->passwordHasher->hash($command->plainPassword),
            $command->displayName,
            Timezone::fromString($command->timezone),
            $this->clock->now(),
        );

        $this->users->add($user);

        return $user;
    }
}
