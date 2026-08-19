<?php

declare(strict_types=1);

namespace App\Tests\Shared\Config;

use App\Community\Infrastructure\Config\CommunitySection;
use App\Community\Infrastructure\Config\NotificationsSection;
use App\Progression\Infrastructure\Config\LevelsSection;
use App\Progression\Infrastructure\Config\TitlesSection;
use App\Progression\Infrastructure\Config\XpSection;
use App\Shared\Infrastructure\Config\GameBalance;
use App\Shared\Infrastructure\Config\GameBalanceLoader;
use App\Training\Infrastructure\Config\ActivityTypesSection;
use App\Training\Infrastructure\Config\TrainingSection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * L'équilibrage se modifie sans revue de code : il doit se refuser tout seul quand il
 * n'a pas de sens. Ce qui est testé ici est autant le refus que le chargement.
 */
final class GameBalanceLoaderTest extends TestCase
{
    public function testLoadsTheShippedBalance(): void
    {
        $balance = self::loadShipped();

        self::assertSame(
            ['minimum_duration_seconds' => 300, 'maximum_duration_seconds' => 14400, 'import_window_days' => 30],
            $balance->sections['training'],
        );

        // Toutes les sections déclarées, et rien d'autre : c'est la garde contre le
        // fichier oublié qui l'affirme, section par section.
        self::assertSame(['activity_types', 'community', 'levels', 'notifications', 'titles', 'training', 'xp'], array_keys($balance->sections));
    }

    public function testDerivesTheRulesetVersionFromTheFolderAndItsContent(): void
    {
        // Le préfixe dit d'où vient la balance, le hash ce qu'elle vaut.
        self::assertMatchesRegularExpression('/^v1-[0-9a-f]{12}$/', self::loadShipped()->version);
    }

    public function testTheRulesetVersionIsStable(): void
    {
        // Deux chargements du même dossier : sans quoi la version ne pourrait pas être
        // stockée avec une transaction d'XP et resservir à expliquer un calcul.
        self::assertSame(
            self::load(self::fixture('coherent'))->version,
            self::load(self::fixture('coherent'))->version,
        );
    }

    public function testTheRulesetVersionIgnoresTheOrderOfTheKeys(): void
    {
        // Permuter deux réglages ne change rien au jeu : le hash ne doit pas bouger,
        // sinon toute relecture de fichier daterait un rééquilibrage qui n'a pas eu lieu.
        self::assertSame(self::hashOf('coherent'), self::hashOf('reordered'));
    }

    public function testTheRulesetVersionFollowsTheValues(): void
    {
        self::assertNotSame(self::hashOf('coherent'), self::hashOf('rebalanced'));
    }

    #[DataProvider('unusableBalances')]
    public function testRefusesAnUnusableBalance(string $fixture, string $expectedMessage): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches($expectedMessage);

        self::load(self::fixture($fixture));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unusableBalances(): iterable
    {
        yield 'seuils incohérents' => ['incoherent', '/sous le plancher/'];
        yield 'réglage manquant' => ['incomplete', '/maximum_duration_seconds/'];
        // Le vrai piège de l'équilibrage : une faute de frappe dans un nom de réglage
        // laisserait le défaut s'appliquer en silence.
        yield 'réglage inconnu' => ['unknown-key', '/maximum_duration_second/'];
        yield 'fichier sans schéma' => ['stray', '/loot\.yaml/'];
        yield 'fichier absent' => ['missing', '/absent/'];
    }

    /**
     * L'équilibrage réellement livré, avec toutes ses sections — la liste doit rester
     * celle de `App\Kernel::build()`, sinon la garde contre les fichiers sans schéma se
     * déclenche et le test le dit tout de suite.
     */
    private static function loadShipped(): GameBalance
    {
        return new GameBalanceLoader(\dirname(__DIR__, 3).'/config/game/v1')
            ->load(new TrainingSection(), new ActivityTypesSection(), new XpSection(), new LevelsSection(), new TitlesSection(), new CommunitySection(), new NotificationsSection());
    }

    /** Les fixtures ne contiennent qu'un `training.yaml` : une seule section à déclarer. */
    private static function load(string $directory): GameBalance
    {
        return new GameBalanceLoader($directory)->load(new TrainingSection());
    }

    /**
     * Le hash seul, sans le préfixe : deux fixtures vivent dans deux dossiers, donc leurs
     * versions complètes diffèrent toujours. C'est ce que le hash couvre qui est en jeu.
     */
    private static function hashOf(string $fixture): string
    {
        return substr(self::load(self::fixture($fixture))->version, -12);
    }

    private static function fixture(string $name): string
    {
        return \dirname(__DIR__, 2).'/Support/GameBalance/'.$name;
    }
}
