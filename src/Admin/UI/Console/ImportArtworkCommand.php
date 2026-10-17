<?php

declare(strict_types=1);

namespace App\Admin\UI\Console;

use App\Admin\Domain\GameArtworkPack;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Réimport idempotent des exports et du calibrage ; aucun inventaire ni réglage de jeu modifié. */
#[AsCommand(name: 'app:artwork:import', description: 'Importe les packs graphiques et les ancrages validés.')]
final class ImportArtworkCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $manager,
        private readonly ValidatorInterface $validator,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDirectory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::OPTIONAL, 'Manifeste de packs JSON.', 'config/artwork/retro-v2.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $input->getArgument('file');
        \assert(\is_string($file));
        $path = str_starts_with($file, '/') ? $file : $this->projectDirectory.'/'.$file;
        $source = file_get_contents($path);
        if (false === $source) {
            throw new LogicException('Manifeste introuvable.');
        }
        $rows = json_decode($source, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($rows) || !array_is_list($rows)) {
            throw new LogicException('Le fichier doit contenir une liste de packs.');
        }
        $this->manager->wrapInTransaction(function () use ($rows): void {
            foreach ($rows as $row) {
                if (!\is_array($row) || !\is_string($row['key'] ?? null) || !\is_string($row['kind'] ?? null)
                    || !\is_string($row['version'] ?? null) || !\is_string($row['name'] ?? null)
                    || !\is_bool($row['active'] ?? null) || !\is_int($row['sortOrder'] ?? null) || !\is_array($row['manifest'] ?? null)) {
                    throw new LogicException('Enveloppe de pack invalide.');
                }
                $pack = $this->manager->getRepository(GameArtworkPack::class)->findOneBy(['key' => $row['key'], 'kind' => $row['kind'], 'version' => $row['version']]) ?? new GameArtworkPack();
                $pack->setKey($row['key']);
                $pack->setKind($row['kind']);
                $pack->setVersion($row['version']);
                $pack->setName($row['name']);
                $pack->setActive($row['active']);
                $pack->setSortOrder($row['sortOrder']);
                $pack->setManifestJson(json_encode($row['manifest'], \JSON_THROW_ON_ERROR));
                $errors = $this->validator->validate($pack);
                if (0 !== $errors->count()) {
                    throw new LogicException((string) $errors);
                }
                $this->manager->persist($pack);
            }
        });
        $output->writeln(\sprintf('%d packs graphiques importés ; progression et équipement conservés.', \count($rows)));

        return Command::SUCCESS;
    }
}
