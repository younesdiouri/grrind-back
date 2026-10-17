<?php

declare(strict_types=1);

namespace App\Admin\UI\Console;

use App\Admin\Domain\GameEnemy;
use App\Admin\Domain\GameItem;
use App\Admin\Infrastructure\GameRulesetPublisher;
use App\Community\Infrastructure\Doctrine\GuildMembershipRepository;
use App\Identity\Infrastructure\Doctrine\UserRepository;
use App\Rewards\Application\EquipItem;
use App\Rewards\Application\EquipItemHandler;
use App\Rewards\Domain\EquipmentSlot;
use App\Rewards\Infrastructure\Doctrine\InventoryItemRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/** Préparation explicite du compte et de sa guilde, sans reset, statistiques inventées ni mots de passe modifiés. */
#[AsCommand(name: 'app:combat:art-demo', description: 'Prépare Cailloux et les bandeaux du compte local et de ses co-équipiers.')]
final class CombatArtDemoCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $manager,
        private readonly GameRulesetPublisher $publisher,
        private readonly UserRepository $users,
        private readonly GuildMembershipRepository $memberships,
        private readonly InventoryItemRepository $inventory,
        private readonly EquipItemHandler $equip,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        private readonly string $gameImageDirectory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Compte local existant, sans changement de session.')
            ->addOption('art-dir', null, InputOption::VALUE_REQUIRED, 'Sources : pebble/{idle,attack,hit}.png et headband/icon.png.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ('dev' !== $this->environment) {
            throw new LogicException('Cette préparation est réservée à l’environnement dev local.');
        }
        $email = $input->getArgument('email');
        \assert(\is_string($email));
        $user = $this->users->ofEmail($email) ?? throw new LogicException('Le compte local demandé est absent.');
        $source = $input->getOption('art-dir');
        if (!\is_string($source)) {
            throw new LogicException('Le dossier --art-dir est obligatoire.');
        }
        $enemy = $this->manager->getRepository(GameEnemy::class)->findOneBy(['key' => 'SAND_JACKAL']);
        $headband = $this->manager->getRepository(GameItem::class)->findOneBy(['key' => 'TEMPERED_HEADBAND']);
        if (!$enemy instanceof GameEnemy || !$headband instanceof GameItem) {
            throw new LogicException('Le catalogue initial Cailloux/Bandeau est absent ; aucune donnée n’a été modifiée.');
        }
        $files = ['idle' => 'pebble/idle.png', 'attack' => 'pebble/attack.png', 'hit' => 'pebble/hit.png', 'icon' => 'headband/icon.png'];
        $filesystem = new Filesystem();
        foreach ($files as $file) {
            if (!is_file($source.'/'.$file)) {
                throw new LogicException('Source manquante : '.$file);
            }
        }
        $filesystem->mkdir($this->gameImageDirectory);
        $paths = [];
        foreach ($files as $pose => $file) {
            $hash = hash_file('sha1', $source.'/'.$file);
            \assert(\is_string($hash));
            $paths[$pose] = $hash.'.png';
            $filesystem->copy($source.'/'.$file, $this->gameImageDirectory.'/'.$paths[$pose]);
        }
        $translations = $enemy->getTranslations();
        $translations['fr']['name'] = 'Cailloux dans la Chaussure';
        $translations['en']['name'] = 'Pebble in the Shoe';
        $changed = $enemy->getTranslations() !== $translations || $enemy->getIdleImagePath() !== $paths['idle']
            || $enemy->getAttackImagePath() !== $paths['attack'] || $enemy->getHitImagePath() !== $paths['hit']
            || $headband->getImagePath() !== $paths['icon'];
        $players = [$user->id()];
        $guild = $this->memberships->ofPlayer($user->id())?->guild();
        if (null !== $guild) {
            $players = array_map(static fn ($member) => $member->playerId(), $guild->members());
        }
        $equipped = $this->manager->wrapInTransaction(function () use ($enemy, $headband, $paths, $translations, $changed, $players): int {
            if ($changed) {
                $enemy->setTranslations($translations);
                $enemy->setIdleImagePath($paths['idle']);
                $enemy->setAttackImagePath($paths['attack']);
                $enemy->setHitImagePath($paths['hit']);
                $headband->setImagePath($paths['icon']);
                $this->publisher->publishCombatArt($this->manager, $enemy, $headband);
            }
            $count = 0;
            foreach ($players as $id) {
                if (null !== $this->inventory->equippedIn($id, EquipmentSlot::Head)) {
                    continue;
                }
                $owned = $this->inventory->ofPlayerAndItem($id, 'TEMPERED_HEADBAND');
                if (null === $owned || $owned->quantity() < 1) {
                    $this->inventory->grant($id, 'TEMPERED_HEADBAND', null, new DateTimeImmutable());
                }
                ($this->equip)(new EquipItem($id, 'TEMPERED_HEADBAND', EquipmentSlot::Head->value));
                ++$count;
            }

            return $count;
        });
        if ($changed) {
            $this->publisher->invalidateAfterCommit();
        }
        $output->writeln(\sprintf('Cailloux prêt. %d bandeau(x) équipé(s), %d joueur(s) local(aux) vérifié(s).', $equipped, \count($players)));

        return Command::SUCCESS;
    }
}
