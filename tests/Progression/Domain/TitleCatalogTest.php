<?php

declare(strict_types=1);

namespace App\Tests\Progression\Domain;

use App\Progression\Domain\PlayerRecord;
use App\Progression\Domain\Title;
use App\Progression\Domain\TitleCatalog;
use App\Progression\Domain\TitleProgress;
use App\Shared\Domain\Activity\Discipline;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Le catalogue est du config-as-code : ce qui se vérifie ici est autant ce qu'il calcule que
 * ce qu'il **refuse**. Un catalogue incohérent doit casser la compilation du conteneur, pas
 * se découvrir le jour où un joueur ne débloque rien.
 */
final class TitleCatalogTest extends TestCase
{
    public function testASessionCountedTwiceUnlocksNothingTwice(): void
    {
        $catalog = self::catalogOf(
            ['id' => 'first_steps', 'condition' => ['type' => 'session_count', 'threshold' => 1]],
            ['id' => 'regular', 'condition' => ['type' => 'session_count', 'threshold' => 25]],
        );

        $record = new PlayerRecord(1, 100, ['RUNNING' => 30]);

        // Le titre déjà acquis n'est pas rendu une seconde fois : c'est ce qui empêche la
        // complétion d'annoncer au joueur, à chaque séance, qu'il vient de débloquer un
        // titre qu'il porte depuis six mois.
        self::assertSame(['regular'], self::idsOf($catalog->newlyUnlockedBy($record, ['first_steps'])));
        self::assertSame([], $catalog->newlyUnlockedBy($record, ['first_steps', 'regular']));
    }

    public function testAnInvalidatedSessionDoesNotTakeBackAnAcquiredTitle(): void
    {
        $catalog = self::catalogOf(
            ['id' => 'regular', 'condition' => ['type' => 'session_count', 'threshold' => 25]],
        );

        // Le relevé est repassé sous le seuil — une séance a été annulée après coup. Le
        // catalogue ne rend aucun *nouveau* déblocage, et il n'a par construction aucun
        // moyen d'en retirer un : rien ici ne sait défaire.
        $record = new PlayerRecord(1, 100, ['RUNNING' => 24]);

        self::assertSame([], $catalog->newlyUnlockedBy($record, ['regular']));
    }

    public function testTheNextTitleIsTheClosestInProportionToItsTarget(): void
    {
        $catalog = self::catalogOf(
            ['id' => 'veteran', 'condition' => ['type' => 'session_count', 'threshold' => 100]],
            ['id' => 'adept', 'condition' => ['type' => 'level_reached', 'threshold' => 10]],
        );

        // 40 séances sur 100 contre le niveau 8 sur 10 : les unités ne se comparent pas, la
        // proportion si. C'est le niveau qui est le plus près d'aboutir.
        $next = $catalog->nextFor(new PlayerRecord(8, 5_000, ['RUNNING' => 40]), []);

        self::assertInstanceOf(TitleProgress::class, $next);
        self::assertSame('adept', $next->title->id);
        self::assertSame(8, $next->current);
        self::assertSame(10, $next->target);
    }

    public function testTheCatalogueOrderBreaksTiesSoTheNextTitleIsDeterministic(): void
    {
        $catalog = self::catalogOf(
            ['id' => 'regular', 'condition' => ['type' => 'session_count', 'threshold' => 10]],
            ['id' => 'veteran', 'condition' => ['type' => 'session_count', 'threshold' => 100]],
        );

        // Cinq séances sur dix et cinquante sur cent sont à la même proportion. Sans ordre
        // signifiant, le prochain titre changerait d'une requête à l'autre.
        $record = new PlayerRecord(1, 0, ['RUNNING' => 5, 'CYCLING' => 45]);

        self::assertSame('regular', $catalog->nextFor($record, [])?->title->id);
    }

    public function testThereIsNoNextTitleOnceEverythingIsUnlocked(): void
    {
        $catalog = self::catalogOf(
            ['id' => 'first_steps', 'condition' => ['type' => 'session_count', 'threshold' => 1]],
        );

        // `null` et non une progression à zéro : il n'y a plus rien à viser, et zéro
        // voudrait dire « à portée ».
        self::assertNull($catalog->nextFor(new PlayerRecord(1, 0), ['first_steps']));
    }

    public function testASpecialisedConditionOnlyCountsItsOwnDiscipline(): void
    {
        $catalog = self::catalogOf(
            ['id' => 'triton', 'condition' => ['type' => 'session_count', 'threshold' => 20, 'discipline' => 'SWIMMING']],
        );

        $record = new PlayerRecord(1, 0, ['RUNNING' => 100, 'SWIMMING' => 19]);

        self::assertSame([], $catalog->newlyUnlockedBy($record, []));
        self::assertSame(19, $catalog->nextFor($record, [])?->current);
    }

    public function testAGlobalSessionCountSumsEveryDiscipline(): void
    {
        $record = new PlayerRecord(1, 0, ['RUNNING' => 12, 'CYCLING' => 13]);

        self::assertSame(25, $record->sessionsIn(null));
        self::assertSame(12, $record->sessionsIn(Discipline::Running));
        self::assertSame(0, $record->sessionsIn(Discipline::Climbing));
    }

    public function testAnEmptyCatalogueIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TitleCatalog([]);
    }

    public function testADuplicateIdentifierIsRefused(): void
    {
        // Deux conditions écrites, une seule évaluée, et rien pour dire laquelle.
        $this->expectException(InvalidArgumentException::class);

        self::catalogOf(
            ['id' => 'veteran', 'condition' => ['type' => 'session_count', 'threshold' => 10]],
            ['id' => 'veteran', 'condition' => ['type' => 'session_count', 'threshold' => 100]],
        );
    }

    public function testAnUnknownConditionTypeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::catalogOf(['id' => 'streaky', 'condition' => ['type' => 'streak_days', 'threshold' => 7]]);
    }

    public function testAnUnknownDisciplineIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::catalogOf(['id' => 'rower', 'condition' => ['type' => 'session_count', 'threshold' => 10, 'discipline' => 'ROWING']]);
    }

    public function testAnIdentifierThatWouldBreakItsTranslationKeyIsRefused(): void
    {
        // L'identifiant préfixe `<id>.name` et `<id>.hint` : une majuscule ou un espace, et
        // le joueur lit la clé brute à la place du titre.
        $this->expectException(InvalidArgumentException::class);

        self::catalogOf(['id' => 'Premier Sang', 'condition' => ['type' => 'session_count', 'threshold' => 1]]);
    }

    /**
     * @param array{id: string, condition: array{type: string, threshold: int, discipline?: string|null}} ...$titles
     */
    private static function catalogOf(array ...$titles): TitleCatalog
    {
        return new TitleCatalog(array_values($titles));
    }

    /**
     * @param list<Title> $titles
     *
     * @return list<string>
     */
    private static function idsOf(array $titles): array
    {
        return array_map(static fn (Title $title): string => $title->id, $titles);
    }
}
