<?php

declare(strict_types=1);

namespace App\Tests\Progression;

use App\Progression\Domain\TitleCatalog;
use App\Progression\Infrastructure\Translation\TitleTranslator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Le filet sous le catalogue de traduction.
 *
 * Un titre ajouté à `config/game/v1/titles.yaml` sans ses libellés ne casse rien : le
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
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        foreach (self::catalog()->all() as $title) {
            foreach (['name', 'hint'] as $part) {
                $key = $title->id.'.'.$part;
                $translated = $translator->trans($key, domain: TitleTranslator::DOMAIN, locale: $locale);

                self::assertNotSame($key, $translated, \sprintf('`%s` manque à translations/titles.%s.yaml.', $key, $locale));
            }
        }
    }

    public function testAHintNeverSpellsOutAThresholdItCouldContradict(): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        $titles = new TitleTranslator($translator);

        foreach (self::catalog()->all() as $title) {
            $hint = $titles->hintOf($title);

            // Le seuil passe en paramètre : un rééquilibrage ne doit pas pouvoir laisser
            // une consigne qui ment. Il reste des consignes sans chiffre — « termine ta
            // première séance » — d'où le `%` cherché plutôt qu'une valeur imposée.
            self::assertStringNotContainsString('%', $hint, \sprintf('Paramètre non substitué dans la consigne de "%s".', $title->id));
        }
    }

    /** Le catalogue livré, construit depuis son paramètre — même geste que pour la courbe. */
    private static function catalog(): TitleCatalog
    {
        $titles = self::getContainer()->getParameter('game.titles.titles');
        self::assertIsArray($titles);

        /** @var list<array{id: string, condition: array{type: string, threshold: int, discipline: string|null}}> $titles */
        return new TitleCatalog($titles);
    }
}
