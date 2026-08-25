<?php

declare(strict_types=1);

namespace App\Tests\Progression\Domain;

use App\Progression\Domain\LevelCurve;
use App\Progression\Domain\ProgressionSnapshot;
use App\Progression\Domain\SnapshotDivergence;
use App\Shared\Domain\Activity\AttributeGains;
use App\Shared\Domain\Activity\Vitality;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Uid\Uuid;

/**
 * La comparaison par table de cas, sans base. C'est le cœur de la commande de
 * reconstruction : ce qu'elle ne sait pas voir, personne ne le verra.
 *
 * La courbe de test est fixe et indépendante de celle qui est livrée — les seuils comptent
 * ici, et les figer dans un test empêcherait de rééquilibrer.
 */
final class SnapshotDivergenceTest extends TestCase
{
    public function testASnapshotThatAgreesWithTheLedgerIsNotADivergence(): void
    {
        self::assertNull(self::compare(self::snapshotAt(250, self::onStrength(250)), 250, self::onStrength(250)));
    }

    public function testCatchesAWrongTotal(): void
    {
        $divergence = self::compare(self::snapshotAt(250, self::onStrength(250)), 400, self::onStrength(400));

        self::assertNotNull($divergence);
        self::assertSame(['stored' => 250, 'expected' => 400], $divergence->fields['totalXp'] ?? null);
    }

    public function testCatchesAColumnThatDriftedWhileTheTotalStayedRight(): void
    {
        // Le cas le plus vicieux, et celui qu'une comparaison du seul total laisserait
        // passer : le joueur lit la bonne XP et le mauvais nombre de points à dépenser.
        $snapshot = self::snapshotAt(400, self::onStrength(400));
        self::corrupt($snapshot, 'earnedSkillPoints', 99);

        $divergence = self::compare($snapshot, 400, self::onStrength(400));

        self::assertNotNull($divergence);
        self::assertSame(['earnedSkillPoints'], array_keys($divergence->fields));
        self::assertSame(['stored' => 99, 'expected' => 2], $divergence->fields['earnedSkillPoints']);
    }

    /**
     * Le même bug, un cran plus bas dans la fiche du personnage (#160) : le total et le
     * niveau sont justes, une seule caractéristique a dérivé. Sans la comparaison
     * champ à champ sur les quatre, ce snapshot passerait pour sain.
     */
    public function testCatchesAnAttributeThatDriftedWhileEverythingElseStayedRight(): void
    {
        $snapshot = self::snapshotAt(400, new AttributeGains(100, 100, 100, 100));
        self::corrupt($snapshot, 'endurance', 999);

        $divergence = self::compare($snapshot, 400, new AttributeGains(100, 100, 100, 100));

        self::assertNotNull($divergence);
        self::assertSame(['endurance'], array_keys($divergence->fields));
        self::assertSame(['stored' => 999, 'expected' => 100], $divergence->fields['endurance']);
    }

    public function testAMissingLineFacingANonEmptyLedgerIsADivergence(): void
    {
        // La ligne aurait dû être posée par le premier crédit. Son absence se lit comme des
        // colonnes à `null`, et elle se répare comme le reste.
        $divergence = self::compare(null, 400, self::onStrength(400));

        self::assertNotNull($divergence);
        self::assertSame(
            ['totalXp', 'level', 'xpIntoLevel', 'earnedSkillPoints', 'strength', 'endurance', 'mobility', 'dexterity', 'vitality'],
            array_keys($divergence->fields),
        );
        self::assertSame(['stored' => null, 'expected' => 400], $divergence->fields['totalXp']);
        self::assertSame(['stored' => null, 'expected' => 400], $divergence->fields['strength']);
        // Tout sur Strength : le coefficient d'équilibre s'effondre, seul le plancher paie.
        self::assertSame(['stored' => null, 'expected' => 100], $divergence->fields['vitality']);
    }

    /**
     * Le même bug qu'à {@see testCatchesAnAttributeThatDriftedWhileEverythingElseStayedRight()},
     * une caractéristique plus bas : Vitality (#161) est dérivée, pas recopiée du ledger — une
     * comparaison qui l'oublierait laisserait un joueur lire une valeur que rien ne recoupe.
     */
    public function testCatchesAVitalityThatDriftedWhileEverythingElseStayedRight(): void
    {
        $snapshot = self::snapshotAt(400, new AttributeGains(100, 100, 100, 100));
        self::corrupt($snapshot, 'vitality', 999);

        $divergence = self::compare($snapshot, 400, new AttributeGains(100, 100, 100, 100));

        self::assertNotNull($divergence);
        self::assertSame(['vitality'], array_keys($divergence->fields));
        self::assertSame(['stored' => 999, 'expected' => 400], $divergence->fields['vitality']);
    }

    public function testAMissingLineFacingAnEmptyLedgerIsTheNormalStateOfANewAccount(): void
    {
        // Un compte qui vient de s'inscrire n'a pas de ligne, et ce n'est pas un défaut :
        // la signaler ferait crier la sonde à chaque inscription.
        self::assertNull(self::compare(null, 0, new AttributeGains(0, 0, 0, 0)));
    }

    public function testAtTheTopOfTheCurveTheNullIsExpectedRatherThanMissing(): void
    {
        // `xpToNextLevel` vaut `null` au niveau maximum : il n'y a plus de suivant, et zéro
        // voudrait dire « atteint ». La comparaison doit lire ce `null` comme une valeur.
        self::assertNull(self::compare(self::snapshotAt(10_000, self::onStrength(10_000)), 10_000, self::onStrength(10_000)));
    }

    private static function compare(?ProgressionSnapshot $stored, int $ledgerTotal, AttributeGains $ledgerAttributes): ?SnapshotDivergence
    {
        return SnapshotDivergence::between(Uuid::v7(), $stored, $ledgerTotal, $ledgerAttributes, self::curve(), self::vitality());
    }

    private static function snapshotAt(int $totalXp, AttributeGains $attributes): ProgressionSnapshot
    {
        $snapshot = ProgressionSnapshot::untouched(Uuid::v7(), self::curve(), self::vitality(), new DateTimeImmutable());
        $snapshot->retotal($totalXp, $attributes, self::curve(), self::vitality(), new DateTimeImmutable());

        return $snapshot;
    }

    /** Tout sur `strength`, pour les cas qui ne portent pas sur la répartition elle-même. */
    private static function onStrength(int $amount): AttributeGains
    {
        return new AttributeGains($amount, 0, 0, 0);
    }

    /**
     * Abîme une colonne sans passer par la projection — c'est exactement ce qu'un bug de
     * projection produirait, et le seul moyen de le simuler sur une entité qui n'a pas de
     * mutateur.
     */
    private static function corrupt(ProgressionSnapshot $snapshot, string $property, int $value): void
    {
        $reflection = new ReflectionProperty(ProgressionSnapshot::class, $property);
        $reflection->setValue($snapshot, $value);
    }

    private static function curve(): LevelCurve
    {
        return new LevelCurve([
            ['level' => 1, 'total_xp' => 0, 'skill_points' => 0],
            ['level' => 2, 'total_xp' => 100, 'skill_points' => 1],
            ['level' => 3, 'total_xp' => 300, 'skill_points' => 1],
        ]);
    }

    /** Un plancher arbitraire mais documenté, indépendant de celui livré — voir `VitalityTest` pour la table complète. */
    private static function vitality(): Vitality
    {
        return new Vitality(250);
    }
}
