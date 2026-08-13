<?php

declare(strict_types=1);

namespace App\Tests\Training;

use App\Shared\Application\PlayerTitle;
use App\Shared\Application\SessionReward;
use App\Shared\Application\XpLine;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Activity\SessionSource;
use App\Training\Application\SessionCompletion;
use App\Training\Domain\Workout;
use App\Training\UI\Http\Response\RewardSummaryResource;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * **L'ordre des champs est l'ordre de l'animation**, et c'est le contrat le plus coûteux
 * à casser du produit.
 *
 * Ce test existait jusqu'ici à travers la route de complétion, qui vient de disparaître
 * (#85). Le laisser mourir avec elle rendrait l'ordre invérifiable pendant tout
 * l'intervalle qui mène au `SyncSummary` (#92) — c'est-à-dire pendant les tickets qui
 * touchent au payload. Il devient donc un test du **payload** et non de la route : moins
 * de couverture, mais sur exactement ce qu'on ne peut pas se permettre de perdre.
 *
 * Il reprendra sa forme fonctionnelle au #92, quand un producteur existera à nouveau.
 */
final class RewardSummaryPayloadTest extends TestCase
{
    public function testTheKeyOrderIsTheAnimationOrder(): void
    {
        $payload = self::summary()->toArray();

        self::assertSame(
            ['session', 'xp', 'level', 'titlesUnlocked', 'loot', 'streak', 'unlockableNodes', 'rulesetVersion'],
            array_keys($payload),
            'Un champ déplacé change la mise en scène. Si c\'est voulu, le client doit être prévenu avant.',
        );

        $level = $payload['level'];
        self::assertIsArray($level);

        // Le palier de départ vient avant la bascule : la barre se pose là où le joueur
        // en était, **puis** elle monte. Un client qui lirait `after` en premier ferait
        // repartir de zéro quiconque a franchi plusieurs niveaux d'un coup.
        self::assertSame(
            ['before', 'xpIntoLevelBefore', 'xpToNextLevelBefore', 'after', 'reached', 'totalXp', 'xpIntoLevel', 'xpToNextLevel', 'skillPointsGranted'],
            array_keys($level),
        );
    }

    /**
     * Trois clés présentes et vides jusqu'aux Lots 5, 6 et 7. Les ajouter plus tard
     * obligerait un client déjà déployé à les traiter comme optionnelles pour toujours.
     */
    public function testTheFutureFieldsAreDeclaredEmptyAndNotAbsent(): void
    {
        $payload = self::summary()->toArray();

        self::assertSame([], $payload['loot']);
        self::assertNull($payload['streak']);
        self::assertSame([], $payload['unlockableNodes']);
    }

    /**
     * Le workout embarqué est la forme unique servie partout ailleurs — plus de statut,
     * les deux bornes toujours présentes. Un client décode un seul type.
     */
    public function testTheEmbeddedWorkoutHasTheSameShapeAsInTheHistory(): void
    {
        $payload = self::summary()->toArray();

        $workout = $payload['session'];
        self::assertIsArray($workout);
        self::assertSame(
            ['id', 'discipline', 'source', 'trust', 'startedAt', 'endedAt', 'durationSeconds'],
            array_keys($workout),
        );
    }

    private static function summary(): RewardSummaryResource
    {
        $workout = Workout::record(
            Uuid::v7(),
            Discipline::Running,
            SessionSource::HealthKit,
            new DateTimeImmutable('2026-08-12T09:00:00+00:00'),
            new DateTimeImmutable('2026-08-12T09:45:00+00:00'),
            new DateTimeImmutable('2026-08-12T19:00:00+00:00'),
        );

        $reward = new SessionReward(
            xpAwarded: 145,
            breakdown: [new XpLine('BASE', 135), new XpLine('STREAK', 10)],
            levelBefore: 1,
            xpIntoLevelBefore: 30,
            xpToNextLevelBefore: 70,
            level: 2,
            totalXp: 175,
            xpIntoLevel: 75,
            xpToNextLevel: 125,
            levelsReached: [2],
            skillPointsGranted: 1,
            titlesUnlocked: [new PlayerTitle(
                'premiers-pas',
                'Premiers pas',
                'La première séance.',
                new DateTimeImmutable('2026-08-12T19:00:00+00:00'),
                1,
                1,
                'SESSIONS',
            )],
            rulesetVersion: 'v1-abcdef',
        );

        return RewardSummaryResource::from(new SessionCompletion($workout, $reward));
    }
}
