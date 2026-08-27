<?php

declare(strict_types=1);

namespace App\Tests\Shared\Config;

use App\Progression\Domain\XpRates;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Infrastructure\Config\GameBalancePass;
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

        // `WorkoutRules` passe par ses paramètres et non par son service, comme
        // `XpRates` juste en dessous : plus rien ne le consomme depuis le retrait du
        // chronomètre (#85) — c'est l'import qui l'appliquera (#91) — donc le conteneur
        // le retire. Que la compilation ait abouti prouve déjà que `TrainingSection` l'a
        // construit et validé sans broncher.
        self::assertSame(300, $container->getParameter('game.training.minimum_duration_seconds'));
        self::assertSame(14400, $container->getParameter('game.training.maximum_duration_seconds'));

        // Le barème d'XP passe par ses paramètres et non par son service : rien ne consomme
        // `XpRates` dans ce conteneur de test, donc il le retire. Que la compilation ait
        // abouti prouve déjà que `XpSection` l'a construit sans broncher ; on vérifie ici
        // qu'il couvre bien toutes les disciplines.
        $baseXpPerHour = $container->getParameter('game.xp.base_xp_per_hour');
        $disciplines = $container->getParameter('game.xp.disciplines');
        self::assertIsInt($baseXpPerHour);
        self::assertIsArray($disciplines);

        /** @var list<array{discipline: string, daily_cap_xp?: int, xp_per_km?: int, xp_per_100m_elevation?: int, credits_xp?: bool}> $disciplines */
        $rates = new XpRates($baseXpPerHour, $disciplines);

        // Le socle ne dépend plus de la discipline (#90) ; c'est le plafond quotidien qui
        // reste par discipline, et c'est lui dont l'absence ferait échouer la construction —
        // sauf pour `WALKING` (#167), qui ne crédite pas et n'a donc pas de plafond du tout.
        foreach (Discipline::cases() as $discipline) {
            if (Discipline::Walking === $discipline) {
                self::assertFalse($rates->credits($discipline));

                continue;
            }

            self::assertTrue($rates->credits($discipline));
            self::assertGreaterThan(0, $rates->dailyCapOf($discipline));
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
