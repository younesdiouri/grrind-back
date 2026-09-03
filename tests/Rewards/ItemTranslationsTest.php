<?php

declare(strict_types=1);

namespace App\Tests\Rewards;

use App\Rewards\Domain\ItemCatalog;
use App\Rewards\Infrastructure\Translation\ItemTranslator;
use App\Shared\Application\GameRulesets;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Le filet sous le catalogue de traduction — même geste que `EnemyTranslationsTest` et
 * `TitleTranslationsTest`.
 *
 * Un objet ajouté au snapshot publié depuis l'administration sans son nom ne casse rien : le traducteur
 * rend la clé, et elle part sur le réseau. C'est un bug silencieux, qui ne se voit qu'en
 * production et seulement par les joueurs d'une des deux langues.
 */
final class ItemTranslationsTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function locales(): iterable
    {
        yield 'français' => ['fr'];
        yield 'anglais' => ['en'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('locales')]
    public function testEveryDeliveredItemHasANameInEveryLocale(string $locale): void
    {
        $catalog = self::catalog();

        foreach ($catalog->all() as $item) {
            $key = strtolower($item->key).'.name';
            $translated = self::translator($locale)->nameOf($item->key);

            self::assertNotSame($key, $translated, \sprintf('`%s` manque dans le snapshot publié (%s).', $key, $locale));
        }
    }

    private static function catalog(): ItemCatalog
    {
        self::bootKernel();
        $catalog = self::getContainer()->get(ItemCatalog::class);
        self::assertInstanceOf(ItemCatalog::class, $catalog);

        return $catalog;
    }

    private static function translator(string $locale): ItemTranslator
    {
        $rulesets = self::getContainer()->get(GameRulesets::class);
        $urls = self::getContainer()->get(UrlGeneratorInterface::class);
        self::assertInstanceOf(GameRulesets::class, $rulesets);
        self::assertInstanceOf(UrlGeneratorInterface::class, $urls);

        return new ItemTranslator(self::locale($locale), $rulesets, $urls);
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
