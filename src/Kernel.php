<?php

declare(strict_types=1);

namespace App;

use App\Admin\Infrastructure\GameRulesetSeed;
use App\Community\Infrastructure\Config\CommunitySection;
use App\Community\Infrastructure\Config\NotificationsSection;
use App\Progression\Infrastructure\Config\AttributeSplitSection;
use App\Progression\Infrastructure\Config\LevelsSection;
use App\Progression\Infrastructure\Config\XpSection;
use App\Shared\Infrastructure\Config\GameBalancePass;
use App\Training\Infrastructure\Config\ActivityTypesSection;
use App\Training\Infrastructure\Config\TrainingSection;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Une section d'équilibrage par module, déclarée au lot correspondant — même règle
     * que les mappings Doctrine : ajouter un module est un acte explicite, et un module
     * oublié se voit tout de suite.
     *
     * La liste est ici et non dans `GameBalancePass` parce que le pass vit dans `Shared`,
     * qui ne connaît aucun module. Le `Kernel` n'appartient à aucune couche : c'est le
     * seul endroit où les sept modules peuvent se rencontrer.
     */
    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new GameBalancePass(
            $this->getProjectDir().'/config/game/v1',
            new TrainingSection(),
            new ActivityTypesSection(),
            new XpSection(),
            new AttributeSplitSection(),
            new LevelsSection(),
            new CommunitySection(),
            new NotificationsSection(),
        ));

        if ('test' === $this->environment) {
            // Les tests unitaires historiques construisent encore des objets purs depuis
            // les tableaux livrés. Ils reçoivent la seed immuable, jamais les YAML retirés ;
            // aucun service runtime ne lit ces paramètres de compatibilité.
            $seed = GameRulesetSeed::data();
            /** @var array<string, mixed> $items */ $items = $seed['items'];
            /** @var array<string, mixed> $titles */ $titles = $seed['titles'];
            /** @var array<string, mixed> $fighter */ $fighter = $seed['fighter'];
            /** @var array<string, mixed> $enemies */ $enemies = $seed['enemies'];
            /** @var array<string, mixed> $bosses */ $bosses = $seed['bosses'];
            /** @var array<string, mixed> $loot */ $loot = $seed['loot'];
            /** @var int $lootVersion */ $lootVersion = $loot['version'];
            /** @var array<string, mixed> $workoutLoot */ $workoutLoot = $loot['workout'];
            /** @var array<string, mixed> $adversaryLoot */ $adversaryLoot = $loot['adversary'];
            /** @var array<string, mixed> $chestLoot */ $chestLoot = $loot['chest'];
            $container->setParameter('game.items.items', self::escapeTestParameters($items));
            $container->setParameter('game.titles.titles', self::escapeTestParameters($titles));
            $container->setParameter('game.combat.fighter', $fighter);
            $container->setParameter('game.combat.enemies', $enemies);
            $container->setParameter('game.combat.bosses', $bosses);
            $container->setParameter('game.loot', self::escapeTestParameters($loot));
            foreach ($fighter as $key => $value) {
                \assert(\is_int($value));
                $container->setParameter('game.combat.fighter.'.$key, $value);
            }
            $container->setParameter('game.loot.version', $lootVersion);
            $container->setParameter('game.loot.workout', $workoutLoot);
            $container->setParameter('game.loot.adversary', $adversaryLoot);
            $container->setParameter('game.loot.chest', $chestLoot);
        }
    }

    /**
     * @param array<int|string, mixed> $values
     *
     * @return array<int|string, mixed>
     */
    private static function escapeTestParameters(array $values): array
    {
        foreach ($values as $key => $value) {
            if (\is_array($value)) {
                $values[$key] = self::escapeTestParameters($value);
            } elseif (\is_string($value)) {
                $values[$key] = str_replace('%', '%%', $value);
            }
        }

        return $values;
    }
}
