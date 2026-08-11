<?php

declare(strict_types=1);

namespace App\Tests\Shared\Config;

use App\Shared\Infrastructure\Config\GameBalancePass;
use App\Training\Domain\TrainingRules;
use App\Training\Infrastructure\Config\TrainingSection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * L'équilibrage est figé dans le conteneur compilé, pas relu à chaque requête. Ce qui se
 * vérifie ici est donc le *moment* autant que le résultat : un YAML incohérent doit
 * casser la compilation, là où il serait rattrapé par la CI et le build de l'image,
 * plutôt que la première requête d'un joueur.
 */
final class GameBalancePassTest extends KernelTestCase
{
    public function testExposesEachSettingAsItsOwnParameter(): void
    {
        $container = self::compile('coherent');

        // Les noms sont ceux que services.yaml injecte : un réglage renommé dans le YAML
        // casse la compilation en nommant le service qui l'attendait.
        self::assertSame(300, $container->getParameter('game.training.minimum_duration_seconds'));
        self::assertSame(14400, $container->getParameter('game.training.maximum_duration_seconds'));
        self::assertSame(900, $container->getParameter('game.training.cooldown_seconds'));
    }

    public function testExposesTheRulesetVersion(): void
    {
        // Le préfixe est le nom du dossier d'équilibrage — ici celui de la fixture.
        self::assertMatchesRegularExpression(
            '/^coherent-[0-9a-f]{12}$/',
            (string) self::compile('coherent')->getParameter('game.ruleset_version'),
        );
    }

    public function testAnIncoherentBalanceFailsTheCompilation(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        self::compile('incoherent');
    }

    public function testTheApplicationContainerCarriesTheShippedBalance(): void
    {
        // Ce que les tests unitaires ne peuvent pas voir : que le pass est bien branché
        // dans Kernel::build(), avec la section de Training déclarée.
        self::bootKernel();
        $container = static::getContainer();

        self::assertMatchesRegularExpression('/^v1-[0-9a-f]{12}$/', (string) $container->getParameter('game.ruleset_version'));

        // Et que le domaine reçoit un objet typé, pas un tableau d'équilibrage.
        $rules = $container->get(TrainingRules::class);
        self::assertInstanceOf(TrainingRules::class, $rules);
        self::assertSame(300, $rules->minimumDurationSeconds);
    }

    private static function compile(string $fixture): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->addCompilerPass(new GameBalancePass(
            \dirname(__DIR__, 2).'/Support/GameBalance/'.$fixture,
            new TrainingSection(),
        ));

        $container->compile();

        return $container;
    }
}
