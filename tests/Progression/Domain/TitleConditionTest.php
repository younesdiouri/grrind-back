<?php

declare(strict_types=1);

namespace App\Tests\Progression\Domain;

use App\Progression\Domain\PlayerRecord;
use App\Progression\Domain\ProgressUnit;
use App\Progression\Domain\Title;
use App\Progression\Domain\TitleCondition;
use App\Progression\Domain\TitleProgress;
use App\Progression\Domain\TitleRequirement;
use App\Shared\Domain\Activity\Discipline;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Une condition est une fonction pure : un relevé entre, un nombre sort. Ce qui compte ici,
 * c'est que **le déblocage et la barre de progression lisent la même règle** — un titre qui
 * s'annoncerait à 24/25 sans se débloquer à 25 serait un bug qu'on ne verrait qu'en
 * production.
 */
final class TitleConditionTest extends TestCase
{
    public function testTheSameRuleDecidesTheProgressAndTheUnlock(): void
    {
        $condition = new TitleCondition(TitleRequirement::TotalXp, 5_000);

        self::assertSame(4_999, $condition->progressOf(new PlayerRecord(10, 4_999)));
        self::assertFalse($condition->isMetBy(new PlayerRecord(10, 4_999)));
        self::assertTrue($condition->isMetBy(new PlayerRecord(10, 5_000)));
    }

    public function testAConditionThatNeedsADisciplineRefusesToBeBuiltWithoutOne(): void
    {
        // « Cumuler 100 heures » sans dire de quoi mélangerait le temps de course et celui
        // de mobilité, qui ne se comparent pas.
        $this->expectException(InvalidArgumentException::class);

        new TitleCondition(TitleRequirement::DisciplineSeconds, 360_000);
    }

    public function testADisciplineOnAConditionThatIgnoresItIsRefused(): void
    {
        // Le silence serait le pire des cas : le titre aurait l'air spécialisé et se
        // débloquerait sur le niveau de tout le monde.
        $this->expectException(InvalidArgumentException::class);

        new TitleCondition(TitleRequirement::LevelReached, 10, Discipline::Running);
    }

    public function testAThresholdThatEveryoneMeetsIsRefused(): void
    {
        // Un titre que tout le monde possède dès l'inscription n'est pas une récompense.
        $this->expectException(InvalidArgumentException::class);

        new TitleCondition(TitleRequirement::SessionCount, 0);
    }

    public function testProgressIsClampedForDisplayButNotForTheDecision(): void
    {
        $title = new Title('marathoner', new TitleCondition(TitleRequirement::DisciplineSeconds, 360_000, Discipline::Running));

        $progress = TitleProgress::of($title, new PlayerRecord(20, 50_000, [], ['RUNNING' => 900_000]));

        // La barre ne dépasse pas, la décision reste prise sur la valeur réelle.
        self::assertSame(360_000, $progress->current);
        self::assertSame(360_000, $progress->target);
        self::assertTrue($progress->isMet);
        self::assertSame(ProgressUnit::Seconds, $progress->unit());
    }

    public function testEachRequirementCarriesTheUnitTheClientDisplays(): void
    {
        // Sans elle, « 43 200 / 360 000 » ne veut rien dire côté client — et lui faire
        // déduire l'unité du type de condition serait la même règle écrite deux fois, des
        // deux côtés du réseau.
        self::assertSame(ProgressUnit::Levels, TitleRequirement::LevelReached->unit());
        self::assertSame(ProgressUnit::Xp, TitleRequirement::TotalXp->unit());
        self::assertSame(ProgressUnit::Sessions, TitleRequirement::SessionCount->unit());
        self::assertSame(ProgressUnit::Seconds, TitleRequirement::DisciplineSeconds->unit());
    }
}
