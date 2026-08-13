<?php

declare(strict_types=1);

namespace App\Tests\Shared\Config;

use App\Progression\Domain\XpRates;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Infrastructure\Config\GameBalancePass;
use App\Training\Domain\WorkoutRules;
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

        // Et que le domaine reçoit des objets typés, pas des tableaux d'équilibrage.
        $rules = $container->get(WorkoutRules::class);
        self::assertInstanceOf(WorkoutRules::class, $rules);
        self::assertSame(300, $rules->minimumDurationSeconds);

        // Le barème d'XP passe par son paramètre et non par son service : rien ne consomme
        // encore `XpRates` — la transaction de complétion est au Lot 4 — donc le conteneur
        // le retire. Que la compilation ait abouti prouve déjà que `XpSection` l'a
        // construit sans broncher ; on vérifie ici qu'il couvre bien les six disciplines.
        $disciplines = $container->getParameter('game.xp.disciplines');
        self::assertIsArray($disciplines);

        /** @var list<array{discipline: string, xp_per_hour: int, daily_cap_xp: int}> $disciplines */
        $rates = new XpRates($disciplines);

        foreach (Discipline::cases() as $discipline) {
            self::assertGreaterThan(0, $rates->perHourOf($discipline));
        }
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
