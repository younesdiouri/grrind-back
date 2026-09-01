<?php

declare(strict_types=1);

namespace App\Tests\Rewards;

use App\Rewards\Domain\ItemCatalog;
use App\Rewards\Infrastructure\Translation\ItemTranslator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
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
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $catalog = self::catalog();

        foreach ($catalog->all() as $item) {
            $key = strtolower($item->key).'.name';
            $translated = $translator->trans($key, domain: ItemTranslator::DOMAIN, locale: $locale);

            self::assertNotSame($key, $translated, \sprintf('`%s` manque à translations/items.%s.yaml.', $key, $locale));
        }
    }

    private static function catalog(): ItemCatalog
    {
        self::bootKernel();
        $items = self::getContainer()->getParameter('game.items.items');
        self::assertIsArray($items);

        /** @var list<array{key: string, rarity: string, slot?: string, kind?: string, price_coins: int, modifiers: list<array{type: string, value: int, discipline?: string}>}> $items */
        return new ItemCatalog($items);
    }
}
