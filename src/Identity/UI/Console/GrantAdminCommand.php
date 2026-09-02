<?php

declare(strict_types=1);

namespace App\Identity\UI\Console;

use App\Identity\Domain\Role;
use App\Identity\Infrastructure\Doctrine\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:user:grant-admin', description: 'Accorde ROLE_ADMIN à un compte avec mot de passe.')]
final class GrantAdminCommand extends Command
{
    public function __construct(private readonly UserRepository $users)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Adresse e-mail du compte existant');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = $input->getArgument('email');
        \assert(\is_string($email));
        $user = $this->users->ofEmail($email);
        if (null === $user) {
            $output->writeln('<error>Compte introuvable.</error>');

            return Command::FAILURE;
        }
        if (null === $user->getPassword()) {
            $output->writeln('<error>Ce compte social ne possède pas de mot de passe et ne peut pas ouvrir /admin/login.</error>');

            return Command::FAILURE;
        }

        $user->grant(Role::Admin);
        $this->users->commit();
        $output->writeln('<info>ROLE_ADMIN accordé (opération idempotente).</info>');

        return Command::SUCCESS;
    }
}
