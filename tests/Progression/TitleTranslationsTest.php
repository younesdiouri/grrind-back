<?php

declare(strict_types=1);

namespace App\Tests\Progression;

use App\Progression\Domain\TitleCatalog;
use App\Progression\Infrastructure\Translation\TitleTranslator;
use App\Shared\Application\GameRulesets;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Le filet sous le catalogue de traduction.
 *
 * Un titre ajouté au snapshot publié depuis l'administration sans ses libellés ne casse rien : le
 * traducteur rend la clé, et `first_steps.name` part sur le réseau. C'est un bug silencieux,
 * qui ne se voit qu'en production et seulement par les joueurs d'une des deux langues — donc
 * exactement le genre que la suite doit attraper. Ce test est la contrepartie du choix
 * d'avoir sorti les libellés de l'équilibrage : le lien entre les deux fichiers n'est plus
 * structurel, il est ici.
 */
final class TitleTranslationsTest extends KernelTestCase
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
    public function testEveryDeliveredTitleHasANameAndAHintInEveryLocale(string $locale): void
    {
        foreach (self::catalog()->all() as $title) {
            foreach (['name', 'hint'] as $part) {
                $key = $title->id.'.'.$part;
                $translated = 'name' === $part ? self::translator($locale)->nameOf($title) : self::translator($locale)->hintOf($title);

                self::assertNotSame($key, $translated, \sprintf('`%s` manque dans le snapshot publié (%s).', $key, $locale));
            }
        }
    }

    public function testAHintNeverSpellsOutAThresholdItCouldContradict(): void
    {
        $titles = self::translator('en');

        foreach (self::catalog()->all() as $title) {
            $hint = $titles->hintOf($title);

            // Le seuil passe en paramètre : un rééquilibrage ne doit pas pouvoir laisser
            // une consigne qui ment. Il reste des consignes sans chiffre — « termine ta
            // première séance » — d'où le `%` cherché plutôt qu'une valeur imposée.
            self::assertStringNotContainsString('%', $hint, \sprintf('Paramètre non substitué dans la consigne de "%s".', $title->id));
        }
    }

    /** Le catalogue runtime, lu dans le snapshot réellement publié. */
    private static function catalog(): TitleCatalog
    {
        $catalog = self::getContainer()->get(TitleCatalog::class);
        self::assertInstanceOf(TitleCatalog::class, $catalog);

        return $catalog;
    }

    private static function translator(string $locale): TitleTranslator
    {
        $rulesets = self::getContainer()->get(GameRulesets::class);
        self::assertInstanceOf(GameRulesets::class, $rulesets);

        return new TitleTranslator(self::locale($locale), $rulesets);
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
