<?php

declare(strict_types=1);

namespace App\Tests\Training;

use App\Shared\Application\SessionReward;
use App\Shared\Application\XpLine;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Activity\WorkoutSource;
use App\Training\Application\SessionCompletion;
use App\Training\Application\SkippedWorkout;
use App\Training\Application\WorkoutImport;
use App\Training\Domain\ImportSkipReason;
use App\Training\Domain\Workout;
use App\Training\UI\Http\Response\SyncSummaryResource;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * L'étage du dessus : l'ordre entre les workouts, et le raccourci qui en est tiré.
 *
 * Sans HTTP et sans base, pour la même raison que {@see RewardSummaryPayloadTest} — ce qui
 * se joue ici est une forme, et une forme se vérifie sur un payload.
 */
final class SyncSummaryPayloadTest extends TestCase
{
    public function testTheKeyOrderIsTheAnimationOrder(): void
    {
        $payload = self::summaryOf(self::credited(1, 0, 90), self::credited(2, 90, 60))->toArray();

        self::assertSame(
            ['syncedAt', 'imported', 'skipped', 'totals', 'rulesetVersion'],
            array_keys($payload),
            'Un champ déplacé change la mise en scène. Si c\'est voulu, le client doit être prévenu avant.',
        );
    }

    /**
     * Chaque élément d'`imported` est **exactement** le `RewardSummary` que le client sait
     * déjà jouer. C'est ce qui rend une synchronisation de dix workouts aussi simple à
     * animer qu'une séance unique.
     */
    public function testEachEntryIsAWholeRewardSummary(): void
    {
        $payload = self::summaryOf(self::credited(1, 0, 90))->toArray();

        self::assertIsArray($payload['imported']);
        self::assertIsArray($payload['imported'][0]);
        self::assertSame(
            ['session', 'xp', 'level', 'titlesUnlocked', 'loot', 'streak', 'unlockableNodes', 'rulesetVersion'],
            array_keys($payload['imported'][0]),
        );
    }

    /**
     * `totals` est **dérivé** de la timeline et de rien d'autre : le premier workout dit
     * d'où le joueur partait, le dernier où il est arrivé. C'est ce qui garantit que les
     * deux ne peuvent pas diverger.
     */
    public function testTheTotalsAreReadAtBothEndsOfTheTimeline(): void
    {
        $payload = self::summaryOf(
            self::credited(level: 1, totalXpBefore: 0, awarded: 90),
            self::credited(level: 3, totalXpBefore: 90, awarded: 60),
            self::credited(level: 5, totalXpBefore: 150, awarded: 45),
        )->toArray();

        self::assertSame(
            [
                'levelBefore' => 1,
                'levelAfter' => 5,
                'xpBefore' => 0,
                'xpAfter' => 195,
                'xpAwarded' => 195,
                'workoutCount' => 3,
            ],
            $payload['totals'],
        );
    }

    /**
     * Pas d'état d'arrivée quand rien n'est arrivé. Écrire « niveau 0 → 0 » mentirait à un
     * joueur de niveau 12, et le client a de toute façon ce test à faire — il n'affiche pas
     * d'écran de résumé pour une synchronisation vide.
     */
    public function testTheTotalsAreNullWhenNothingWasCredited(): void
    {
        $payload = self::summaryOf()->toArray();

        self::assertNull($payload['totals']);
        self::assertSame([], $payload['imported']);
    }

    /**
     * Un workout qui disparaît sans un mot est un bug du point de vue du joueur, même quand
     * le serveur a raison de l'écarter. Le type d'activité brut voyage avec la raison :
     * sans lui, « le curling n'est pas encore un sport chez nous » est inécrivable.
     */
    public function testEachSkippedWorkoutIsNamedAndNotCounted(): void
    {
        $import = new WorkoutImport(
            new DateTimeImmutable('2026-08-13T19:00:00+00:00'),
            [],
            [new SkippedWorkout('HK-CURLING', 'curling', ImportSkipReason::UnsupportedActivity)],
        );

        self::assertSame(
            [['externalId' => 'HK-CURLING', 'activityType' => 'curling', 'reason' => 'UNSUPPORTED_ACTIVITY']],
            SyncSummaryResource::from($import, 'v1-abcdef')->toArray()['skipped'],
        );
    }

    /**
     * Rien n'est tronqué : au-delà d'une vingtaine de workouts, tout jouer prend plusieurs
     * minutes, mais c'est une décision de mise en scène et elle appartient au client. Le
     * serveur qui couperait lui retirerait le choix, et personne ne saurait plus ce que
     * l'import a réellement crédité.
     */
    public function testALongSyncIsNeverTruncated(): void
    {
        $credited = [];
        for ($i = 0; $i < 60; ++$i) {
            $credited[] = self::credited(1 + $i, 30 * $i, 30);
        }

        $payload = self::summaryOf(...$credited)->toArray();

        self::assertIsArray($payload['imported']);
        self::assertCount(60, $payload['imported']);
        self::assertIsArray($payload['totals']);
        self::assertSame(60, $payload['totals']['workoutCount']);
    }

    private static function summaryOf(SessionCompletion ...$imported): SyncSummaryResource
    {
        return SyncSummaryResource::from(
            new WorkoutImport(new DateTimeImmutable('2026-08-13T19:00:00+00:00'), array_values($imported), []),
            'v1-abcdef',
        );
    }

    private static function credited(int $level, int $totalXpBefore, int $awarded): SessionCompletion
    {
        $workout = Workout::record(
            Uuid::v7(),
            Discipline::Running,
            WorkoutSource::AppleHealth,
            new DateTimeImmutable('2026-08-12T09:00:00+00:00'),
            new DateTimeImmutable('2026-08-12T09:45:00+00:00'),
            new DateTimeImmutable('2026-08-12T19:00:00+00:00'),
        );

        return new SessionCompletion($workout, new SessionReward(
            xpAwarded: $awarded,
            breakdown: [new XpLine('BASE', $awarded)],
            levelBefore: $level,
            xpIntoLevelBefore: 0,
            xpToNextLevelBefore: 100,
            level: $level,
            totalXp: $totalXpBefore + $awarded,
            xpIntoLevel: 0,
            xpToNextLevel: 100,
            levelsReached: [],
            skillPointsGranted: 0,
            titlesUnlocked: [],
            rulesetVersion: 'v1-abcdef',
        ));
    }
}
