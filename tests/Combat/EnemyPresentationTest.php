<?php

declare(strict_types=1);

namespace App\Tests\Combat;

use App\Combat\Infrastructure\Translation\EnemyTranslator;
use App\Shared\Application\GameRulesets;
use App\Shared\Infrastructure\Config\GameRulesetVersion;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Translation\Translator;

final class EnemyPresentationTest extends TestCase
{
    public function testPresentationResolvesInactiveBossesAndRefreshesWithPublishedRevision(): void
    {
        $paths = ['idle' => str_repeat('a', 40).'.png', 'attack' => str_repeat('b', 40).'.png', 'hit' => str_repeat('c', 40).'.png'];
        $snapshot = ['combat' => ['bosses' => [['key' => 'OLD_BOSS', 'active' => false, 'image_paths' => $paths, 'translations' => ['fr' => ['introduction' => 'Bonjour'], 'en' => ['introduction' => 'Hello']]]]]];
        $revision = 1;
        $rulesets = $this->createStub(GameRulesets::class);
        $rulesets->method('snapshot')->willReturnCallback(static function () use (&$snapshot): array { return $snapshot; });
        $rulesets->method('revision')->willReturnCallback(static function () use (&$revision): int { return $revision; });
        $locale = new Translator('fr');
        $routes = new RouteCollection();
        $routes->add('game_image', new Route('/game-images/{name}'));
        $translator = new EnemyTranslator($locale, $rulesets, new UrlGenerator($routes, new RequestContext(host: 'images.example', scheme: 'https')));
        self::assertSame('Bonjour', $translator->introductionOf('OLD_BOSS'));
        $urls = $translator->imageUrlsOf('OLD_BOSS');
        self::assertNotNull($urls);
        foreach ($paths as $pose => $path) {
            self::assertSame('https://images.example/game-images/'.$path, $urls[$pose]);
        }
        $locale->setLocale('de');
        self::assertSame('Hello', $translator->introductionOf('OLD_BOSS'));
        $snapshot['combat']['bosses'][0]['translations']['en']['introduction'] = " \u{00a0} ";
        ++$revision;
        self::assertSame('Bonjour', $translator->introductionOf('OLD_BOSS'));
        $snapshot['combat']['bosses'][0]['translations']['fr']['introduction'] = '';
        ++$revision;
        self::assertNull($translator->introductionOf('OLD_BOSS'));
        $snapshot['combat']['bosses'] = [];
        ++$revision;
        self::assertNull($translator->imageUrlsOf('OLD_BOSS'));
        self::assertNull($translator->introductionOf('OLD_BOSS'));
    }

    public function testLegacySnapshotAndMissingKeysHaveNoPresentation(): void
    {
        $rulesets = $this->createStub(GameRulesets::class);
        $rulesets->method('snapshot')->willReturn(['combat' => ['enemies' => [['key' => 'LEGACY', 'translations' => ['fr' => ['name' => 'Ancien']]]]]]);
        $translator = new EnemyTranslator(new Translator('fr'), $rulesets);
        self::assertSame('Ancien', $translator->nameOf('LEGACY'));
        foreach (['LEGACY', 'MISSING'] as $key) {
            self::assertNull($translator->imageUrlsOf($key));
            self::assertNull($translator->introductionOf($key));
        }
    }

    public function testPresentationDoesNotChangeGameplayVersion(): void
    {
        $snapshot = ['combat' => ['enemies' => [['key' => 'MOB', 'hp' => 100]]]];
        $version = GameRulesetVersion::of($snapshot);
        $snapshot['combat']['enemies'][0]['image_paths'] = ['idle' => 'a.png', 'attack' => 'b.png', 'hit' => 'c.png'];
        $snapshot['combat']['enemies'][0]['translations'] = ['fr' => ['introduction' => 'Bonjour']];
        self::assertSame($version, GameRulesetVersion::of($snapshot));
    }
}
