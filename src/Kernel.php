<?php

declare(strict_types=1);

namespace App;

use App\Combat\Infrastructure\Config\CombatSection;
use App\Community\Infrastructure\Config\CommunitySection;
use App\Community\Infrastructure\Config\NotificationsSection;
use App\Progression\Infrastructure\Config\AttributeSplitSection;
use App\Progression\Infrastructure\Config\LevelsSection;
use App\Progression\Infrastructure\Config\TitlesSection;
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
            new TitlesSection(),
            new CommunitySection(),
            new NotificationsSection(),
            new CombatSection(),
        ));
    }
}
