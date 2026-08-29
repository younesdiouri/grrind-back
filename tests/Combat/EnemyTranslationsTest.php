<?php

declare(strict_types=1);

namespace App\Tests\Combat;

use App\Combat\Domain\EnemyCatalog;
use App\Combat\Infrastructure\Translation\EnemyTranslator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Le filet sous le catalogue de traduction — même geste que `TitleTranslationsTest`.
 *
 * Un ennemi ajouté à `config/game/v1/combat.yaml` sans son nom ne casse rien : le
 * traducteur rend la clé, et `sand_jackal.name` part sur le réseau. C'est un bug silencieux,
 * qui ne se voit qu'en production et seulement par les joueurs d'une des deux langues.
 */
final class EnemyTranslationsTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function locales(): iterable
    {
        // Celles de `framework.enabled_locales` : ouvrir une langue sans la traduire doit
        // faire rougir la CI, pas passer inaperçu.
        yield 'français' => ['fr'];
        yield 'anglais' => ['en'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('locales')]
    public function testEveryDeliveredEnemyHasANameInEveryLocale(string $locale): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        foreach (self::catalog()->all() as $enemy) {
            $key = strtolower($enemy->key).'.name';
            $translated = $translator->trans($key, domain: EnemyTranslator::DOMAIN, locale: $locale);

            self::assertNotSame($key, $translated, \sprintf('`%s` manque à translations/enemies.%s.yaml.', $key, $locale));
        }
    }

    private static function catalog(): EnemyCatalog
    {
        self::bootKernel();
        $enemies = self::getContainer()->getParameter('game.combat.enemies');
        self::assertIsArray($enemies);

        /** @var list<array{key: string, level: int, hp: int, damage: int, mitigation_permille: int, extra_turn_permille: int}> $enemies */
        return new EnemyCatalog($enemies);
    }
}
