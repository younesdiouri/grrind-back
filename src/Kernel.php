<?php

declare(strict_types=1);

namespace App;

use App\Progression\Infrastructure\Config\LevelsSection;
use App\Progression\Infrastructure\Config\XpSection;
use App\Shared\Infrastructure\Config\GameBalancePass;
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
     * seul endroit où les six modules peuvent se rencontrer.
     */
    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new GameBalancePass(
            $this->getProjectDir().'/config/game/v1',
            new TrainingSection(),
            new XpSection(),
            new LevelsSection(),
        ));
    }
}
