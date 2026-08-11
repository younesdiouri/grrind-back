<?php

declare(strict_types=1);

namespace App\Tests\Progression\Domain;

use App\Progression\Domain\LevelCurve;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La projection « total d'XP → niveau ». Elle n'a ni état ni historique : c'est ce qui
 * permet à `progression_snapshot` de n'être qu'un cache et à la reconstruction (#20) de
 * retomber sur ses pieds.
 *
 * La courbe utilisée ici est une fixture à quatre niveaux, délibérément différente de celle
 * qui est livrée : un rééquilibrage doit pouvoir changer `levels.yaml` sans casser une
 * table de cas qui parle de la mécanique.
 */
final class LevelCurveTest extends TestCase
{
    #[DataProvider('totals')]
    public function testProjectsATotalOntoTheCurve(int $totalXp, int $level, int $into, ?int $toNext, int $skillPoints): void
    {
        $standing = self::curve()->standingAt($totalXp);

        self::assertSame($level, $standing->level);
        self::assertSame($into, $standing->xpIntoLevel);
        self::assertSame($toNext, $standing->xpToNextLevel);
        self::assertSame($skillPoints, $standing->earnedSkillPoints);
    }

    /**
     * Fixture : 1 → 0 (0 pt), 2 → 100 (1 pt), 3 → 300 (1 pt), 4 → 600 (2 pts).
     *
     * @return iterable<string, array{int, int, int, ?int, int}>
     */
    public static function totals(): iterable
    {
        yield 'compte neuf' => [0, 1, 0, 100, 0];
        yield 'en route vers le 2' => [40, 1, 40, 60, 0];

        // Le seuil est atteint *à* la valeur, pas au-dessus : 100 XP est le niveau 2, sans
        // quoi le joueur verrait sa barre pleine sans passer de niveau.
        yield 'pile sur le seuil' => [100, 2, 0, 200, 1];
        yield 'l\'XP qui manque' => [99, 1, 99, 1, 0];

        yield 'au milieu du 2' => [220, 2, 120, 80, 1];
        yield 'pile sur le 3' => [300, 3, 0, 300, 2];

        // Les points sont cumulés jusqu'au niveau atteint, celui-ci inclus.
        yield 'dernier niveau' => [600, 4, 0, null, 4];
        yield 'au-delà du dernier' => [10_000, 4, 9400, null, 4];
    }

    public function testTheLastLevelHasNoNext(): void
    {
        $standing = self::curve()->standingAt(600);

        // `null` et non zéro : zéro voudrait dire « le suivant est atteint », soit le
        // contraire. Le client s'appuie dessus pour cesser d'afficher une barre.
        self::assertTrue($standing->isMaxed());
        self::assertFalse(self::curve()->standingAt(599)->isMaxed());
    }

    public function testANegativeTotalStaysAtTheFloor(): void
    {
        // Un total négatif ne peut venir que d'une annulation qui reprend plus que ce que le
        // joueur a jamais eu. Lever ici bloquerait la correction qu'on est en train
        // d'écrire ; le joueur retombe simplement au socle.
        $standing = self::curve()->standingAt(-500);

        self::assertSame(1, $standing->level);
        self::assertSame(0, $standing->xpIntoLevel);
        self::assertSame(0, $standing->earnedSkillPoints);
    }

    public function testKnowsWhereItStops(): void
    {
        self::assertSame(4, self::curve()->maxLevel());
    }

    /**
     * @param list<array{level: int, total_xp: int, skill_points: int}> $levels
     */
    #[DataProvider('unusableCurves')]
    public function testRefusesAnUnusableCurve(array $levels, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches($expectedMessage);

        new LevelCurve($levels);
    }

    /**
     * @return iterable<string, array{list<array{level: int, total_xp: int, skill_points: int}>, string}>
     */
    public static function unusableCurves(): iterable
    {
        yield 'courbe vide' => [[], '/sans niveau/'];

        yield 'ne commence pas au niveau 1' => [
            [['level' => 2, 'total_xp' => 0, 'skill_points' => 0]],
            '/commencer au niveau 1/',
        ];

        // Un socle à 100 XP mettrait tout compte neuf hors de la courbe.
        yield 'le socle coûte de l\'XP' => [
            [['level' => 1, 'total_xp' => 100, 'skill_points' => 0]],
            '/commencer au niveau 1/',
        ];

        // Un trou ferait sauter un niveau à tout le monde, en silence.
        yield 'un niveau manquant' => [
            [
                ['level' => 1, 'total_xp' => 0, 'skill_points' => 0],
                ['level' => 3, 'total_xp' => 100, 'skill_points' => 1],
            ],
            '/sans trou/',
        ];

        // Un seuil qui redescend rendrait la projection ambiguë : deux niveaux
        // revendiqueraient le même total.
        yield 'un seuil qui redescend' => [
            [
                ['level' => 1, 'total_xp' => 0, 'skill_points' => 0],
                ['level' => 2, 'total_xp' => 100, 'skill_points' => 1],
                ['level' => 3, 'total_xp' => 100, 'skill_points' => 1],
            ],
            '/au-dessus du précédent/',
        ];

        yield 'un niveau qui retire un point' => [
            [
                ['level' => 1, 'total_xp' => 0, 'skill_points' => 0],
                ['level' => 2, 'total_xp' => 100, 'skill_points' => -1],
            ],
            '/retirer de point/',
        ];
    }

    /**
     * @return list<array{level: int, total_xp: int, skill_points: int}>
     */
    public static function fixture(): array
    {
        return [
            ['level' => 1, 'total_xp' => 0, 'skill_points' => 0],
            ['level' => 2, 'total_xp' => 100, 'skill_points' => 1],
            ['level' => 3, 'total_xp' => 300, 'skill_points' => 1],
            ['level' => 4, 'total_xp' => 600, 'skill_points' => 2],
        ];
    }

    private static function curve(): LevelCurve
    {
        return new LevelCurve(self::fixture());
    }
}
