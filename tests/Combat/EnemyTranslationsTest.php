<?php

declare(strict_types=1);

namespace App\Tests\Combat;

use App\Combat\Domain\EnemyCatalog;
use App\Combat\Infrastructure\Translation\EnemyTranslator;
use App\Shared\Application\GameRulesets;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Le filet sous le catalogue de traduction — même geste que `TitleTranslationsTest`.
 *
 * Un ennemi ou un boss ajouté au snapshot publié depuis l'administration sans son nom ne casse rien : le
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
        $catalog = self::catalog();

        // Les boss (#219) partagent le même traducteur que les ennemis ordinaires : aucun
        // adversaire n'est livré sans nom, dans aucune des deux listes.
        foreach ([...$catalog->all(), ...$catalog->bosses()] as $enemy) {
            $key = strtolower($enemy->key).'.name';
            $translated = self::translator($locale)->nameOf($enemy->key);

            self::assertNotSame($key, $translated, \sprintf('`%s` manque dans le snapshot publié (%s).', $key, $locale));
        }
    }

    private static function catalog(): EnemyCatalog
    {
        self::bootKernel();
        $catalog = self::getContainer()->get(EnemyCatalog::class);
        self::assertInstanceOf(EnemyCatalog::class, $catalog);

        return $catalog;
    }

    private static function translator(string $locale): EnemyTranslator
    {
        $rulesets = self::getContainer()->get(GameRulesets::class);
        self::assertInstanceOf(GameRulesets::class, $rulesets);

        return new EnemyTranslator(self::locale($locale), $rulesets);
    }

    private static function locale(string $locale): TranslatorInterface
    {
        return new class($locale) implements TranslatorInterface {
            public function __construct(private readonly string $locale)
            {
            }

            /** @param array<string, mixed> $parameters */
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id;
            }

            public function getLocale(): string
            {
                return $this->locale;
            }
        };
    }
}
