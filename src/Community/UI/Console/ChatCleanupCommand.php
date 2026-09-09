<?php

declare(strict_types=1);

namespace App\Community\UI\Console;

use App\Community\Infrastructure\Storage\CleanupChatImages;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:chat:cleanup', description: 'Supprime les images de chat sans message, sur la machine portant le volume.')]
final class ChatCleanupCommand extends Command
{
    public function __construct(private readonly CleanupChatImages $cleanup)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        new SymfonyStyle($input, $output)->success(\sprintf('%d image(s) orpheline(s) supprimée(s).', $this->cleanup->clean()));

        return Command::SUCCESS;
    }
}
