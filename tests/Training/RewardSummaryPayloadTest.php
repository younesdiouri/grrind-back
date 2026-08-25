<?php

declare(strict_types=1);

namespace App\Tests\Training;

use App\Shared\Application\PlayerTitle;
use App\Shared\Application\SessionReward;
use App\Shared\Application\XpLine;
use App\Shared\Domain\Activity\AttributeGains;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Activity\WorkoutSource;
use App\Training\Application\SessionCompletion;
use App\Training\Domain\Workout;
use App\Training\UI\Http\Response\RewardSummaryResource;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * **L'ordre des champs est l'ordre de l'animation**, et c'est le contrat le plus coûteux à
 * casser du produit.
 *
 * Ce test tient l'ordre **d'un** workout, sans HTTP et sans base : c'est du payload pur, et
 * ça doit le rester pour qu'un changement de mise en scène échoue en une seconde et sans
 * dépendre d'une route. {@see SyncSummaryPayloadTest} tient le niveau au-dessus, et
 * {@see ImportSyncSummaryTest} vérifie que la vraie API rend bien tout ça.
 */
final class RewardSummaryPayloadTest extends TestCase
{
    public function testTheKeyOrderIsTheAnimationOrder(): void
    {
        $payload = self::summary()->toArray();

        self::assertSame(
            ['session', 'xp', 'attributes', 'level', 'titlesUnlocked', 'loot', 'streak', 'unlockableNodes', 'rulesetVersion'],
            array_keys($payload),
            'Un champ déplacé change la mise en scène. Si c\'est voulu, le client doit être prévenu avant.',
        );

        // `attributes` entre `xp` et `level`, jamais ailleurs (#162) : les caractéristiques
        // sont la conséquence directe de l'XP qui vient de tomber, le niveau celle du total
        // qu'elles composent.
        $attributes = $payload['attributes'];
        self::assertIsArray($attributes);
        self::assertSame(['strength', 'endurance', 'mobility', 'dexterity', 'vitality'], array_keys($attributes));

        $strength = $attributes['strength'];
        self::assertIsArray($strength);
        // Comme `xp.awarded` précède `xp.breakdown` : ce que la séance a rapporté d'abord,
        // puis la jauge qu'elle anime.
        self::assertSame(['gained', 'before', 'after'], array_keys($strength));

        $vitality = $attributes['vitality'];
        self::assertIsArray($vitality);
        // Pas de `gained` ici : Vitality ne reçoit jamais d'XP directement.
        self::assertSame(['before', 'after'], array_keys($vitality));

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
     * Le champ le plus intéressant du lot (#162) : Vitality bouge **sans avoir reçu la
     * moindre XP** de cette séance, parce que la répartition du jour a rééquilibré la
     * pratique. Ici, Mobility n'a rien reçu de cette séance — `gained` vaut zéro, `before`
     * égale `after` — et Vitality bouge quand même : la preuve, au niveau du payload, que
     * `RewardSummaryResource` ne la dérive jamais de `attributeGains`.
     */
    public function testVitalityCanMoveWithoutHavingReceivedAnyXp(): void
    {
        $payload = self::summary()->toArray();

        $attributes = $payload['attributes'];
        self::assertIsArray($attributes);

        $mobility = $attributes['mobility'];
        self::assertIsArray($mobility);
        self::assertSame(0, $mobility['gained']);
        self::assertSame($mobility['before'], $mobility['after']);

        $vitality = $attributes['vitality'];
        self::assertIsArray($vitality);
        self::assertNotSame($vitality['before'], $vitality['after']);
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
            ['id', 'discipline', 'source', 'trust', 'startedAt', 'endedAt', 'durationSeconds', 'distanceMeters', 'calories', 'elevationGainMeters', 'averageHeartRate', 'externalId'],
            array_keys($workout),
        );
    }

    private static function summary(): RewardSummaryResource
    {
        $workout = Workout::record(
            Uuid::v7(),
            Discipline::Running,
            WorkoutSource::AppleHealth,
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
            // Cette séance ne rapporte rien à Mobility (`0`) : c'est le cas que
            // {@see testVitalityCanMoveWithoutHavingReceivedAnyXp} exploite — Mobility
            // reste immobile pendant que Vitality bouge quand même.
            attributeGains: new AttributeGains(135, 10, 0, 0),
            attributesBefore: new AttributeGains(5_000, 1_000, 0, 200),
            attributesAfter: new AttributeGains(5_135, 1_010, 0, 200),
            vitalityBefore: 300,
            vitalityAfter: 310,
            rulesetVersion: 'v1-abcdef',
        );

        return RewardSummaryResource::from(new SessionCompletion($workout, $reward));
    }
}
