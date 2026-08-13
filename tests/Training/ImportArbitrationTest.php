<?php

declare(strict_types=1);

namespace App\Tests\Training;

use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Workouts;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ce que l'import refuse de créditer, et pourquoi.
 *
 * Trois arbitrages, trois pannes de produit qu'ils empêchent :
 *
 * - **la fenêtre** empêche trois ans d'Apple Health d'amener un joueur au niveau 60 avant
 *   sa première course *pour* Grrind. Le ledger est append-only : ça ne se rattrape pas ;
 * - **le chevauchement** empêche un joueur qui a Apple Exercice *et* Strava de tripler son
 *   XP sans rien faire d'illégitime. C'est le cas **par défaut** d'une partie des
 *   utilisateurs, pas un abus à punir ;
 * - **le plancher et le plafond** trient le faux départ de la séance, et l'enregistrement
 *   oublié de la journée de sport.
 *
 * Chaque refus est **nommé**. « 2 workouts ignorés » ne dit rien ; « 2 déjà importés, 1 hors
 * fenêtre » dit tout.
 */
final class ImportArbitrationTest extends ApiTestCase
{
    use Workouts;

    /**
     * Le workout entre en base — le joueur retrouve son passé — mais ne rapporte rien. Le
     * premier import devient un moment de produit au lieu d'un mur.
     */
    public function testAWorkoutOlderThanTheWindowIsStoredButNotCredited(): void
    {
        $bob = $this->openAccount();

        $response = $this->import($bob, [self::candidate(daysAgo: 200)]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);
        self::assertSame([], $body['imported'], 'Hors fenêtre : aucun crédit.');
        self::assertSame(
            [['externalId' => 'HK-001', 'activityType' => 'running', 'reason' => 'OUT_OF_WINDOW']],
            $body['skipped'],
        );

        // Écarté du crédit, pas de l'historique : c'est la seule raison de la liste qui
        // laisse une ligne en base.
        self::assertSame(1, $this->countWorkouts());
        self::assertSame(0, $this->ledgerSize());
        self::assertSame(0, $this->snapshotTotalOf($bob));
    }

    /**
     * Le cas réel du premier import : des archives et quelques séances récentes dans le
     * même lot. Les récentes comptent, les archives sont là sans compter.
     */
    public function testTheArchiveAndTheRecentSessionsTravelInTheSameBatch(): void
    {
        $bob = $this->openAccount();

        $this->import($bob, [
            self::candidate(externalId: 'HK-VIEUX-1', daysAgo: 400),
            self::candidate(externalId: 'HK-VIEUX-2', daysAgo: 90),
            self::candidate(externalId: 'HK-RECENT', daysAgo: 3),
        ]);

        self::assertSame(3, $this->countWorkouts());
        self::assertSame(1, $this->ledgerSize());
        self::assertSame(30, $this->snapshotTotalOf($bob));
    }

    /**
     * Un workout hors fenêtre n'apprend rien à personne : pas de crédit, donc pas
     * d'événement, donc rien dans le classement du Lot 8.
     */
    public function testAnArchivedWorkoutPublishesNoEvent(): void
    {
        $bob = $this->openAccount();

        $this->import($bob, [self::candidate(daysAgo: 200)]);

        self::assertSame(0, $this->outboxSize());
    }

    /**
     * Deux enregistrements du même effort — Apple Exercice et Strava écrivent tous les deux
     * dans HealthKit. Un seul entre.
     */
    public function testTwoRecordsOfTheSameEffortGiveOneWorkout(): void
    {
        $bob = $this->openAccount();

        $response = $this->import($bob, [
            self::candidate(externalId: 'APPLE-EXERCICE', daysAgo: 2, at: '07:00:00', durationSeconds: 2700),
            self::candidate(externalId: 'STRAVA-VIA-HK', daysAgo: 2, at: '07:00:12', durationSeconds: 2640),
        ]);

        $body = self::decode($response);
        self::assertIsArray($body['imported']);
        self::assertCount(1, $body['imported']);
        self::assertSame(1, $this->countWorkouts());
        self::assertIsArray($body['skipped']);
        self::assertCount(1, $body['skipped']);
        self::assertIsArray($body['skipped'][0]);
        self::assertSame('OVERLAPS', $body['skipped'][0]['reason']);
    }

    /**
     * **C'est celui qui en dit le plus au joueur qui gagne, pas le premier arrivé.** Deux
     * applications ne démarrent jamais à la même seconde : garder celle qui commence en
     * premier reviendrait à tirer au sort, et à laisser parfois gagner l'enregistrement qui
     * ne porte ni distance ni cardio.
     */
    public function testTheRicherRecordWinsTheOverlapEvenWhenItStartsLast(): void
    {
        $bob = $this->openAccount();

        $this->import($bob, [
            // Le plus ancien, mais nu : c'est l'entrée « Autre » d'Apple.
            self::candidate(externalId: 'APPLE-NU', daysAgo: 2, at: '07:00:00', durationSeconds: 2700),
            // Douze secondes plus tard, mais avec tout ce que Strava sait.
            self::candidate(
                externalId: 'STRAVA-COMPLET',
                daysAgo: 2,
                at: '07:00:12',
                durationSeconds: 2640,
                distanceMeters: 8400,
                calories: 520,
                averageHeartRate: 149,
            ),
        ]);

        $row = $this->connection()->fetchAssociative('SELECT external_id, distance_meters FROM workout');

        self::assertSame(['external_id' => 'STRAVA-COMPLET', 'distance_meters' => 8400], $row);
    }

    /**
     * Le même lot dans l'autre sens doit écrire le même workout. Sans départage total, le
     * gagnant dépendrait de l'ordre dans lequel le client a empilé ses pages.
     */
    public function testTheWinnerDoesNotDependOnTheOrderOfTheBatch(): void
    {
        $bob = $this->openAccount();
        $alice = $this->openAccount('alice@grrind.app', 'Alice');

        $nu = self::candidate(externalId: 'APPLE-NU', daysAgo: 2, at: '07:00:00', durationSeconds: 2700);
        $complet = self::candidate(externalId: 'STRAVA-COMPLET', daysAgo: 2, at: '07:00:12', durationSeconds: 2640, distanceMeters: 8400);

        $this->import($bob, [$nu, $complet]);
        $this->import($alice, [$complet, $nu]);

        $chosen = $this->connection()->fetchFirstColumn('SELECT DISTINCT external_id FROM workout');

        self::assertSame(['STRAVA-COMPLET'], $chosen);
    }

    /**
     * Le chevauchement se juge aussi contre ce qui est **déjà en base** : la seconde app
     * peut très bien synchroniser une heure après la première.
     */
    public function testAWorkoutOverlappingOneAlreadyStoredIsSkipped(): void
    {
        $bob = $this->openAccount();
        $this->import($bob, [self::candidate(externalId: 'APPLE-EXERCICE', daysAgo: 2, at: '07:00:00', durationSeconds: 2700)]);

        $response = $this->import(
            $bob,
            [self::candidate(externalId: 'STRAVA-VIA-HK', daysAgo: 2, at: '07:10:00', durationSeconds: 1800)],
            key: 'seconde-synchro',
        );

        $body = self::decode($response);
        self::assertSame([], $body['imported']);
        self::assertIsArray($body['skipped']);
        self::assertIsArray($body['skipped'][0]);
        self::assertSame('OVERLAPS', $body['skipped'][0]['reason']);
        self::assertSame(1, $this->countWorkouts());
    }

    /**
     * Une ligne déjà en base gagne toujours, même moins complète. La détrôner voudrait dire
     * supprimer un workout crédité et contrepasser son écriture au ledger, pour une séance
     * que le joueur a déjà vue défiler — le prix de l'append-only, et il est juste.
     */
    public function testAStoredWorkoutIsNeverDethronedByARicherLatecomer(): void
    {
        $bob = $this->openAccount();
        $this->import($bob, [self::candidate(externalId: 'APPLE-NU', daysAgo: 2, at: '07:00:00', durationSeconds: 2700)]);

        $this->import(
            $bob,
            [self::candidate(externalId: 'STRAVA-COMPLET', daysAgo: 2, at: '07:00:12', durationSeconds: 2640, distanceMeters: 8400)],
            key: 'seconde-synchro',
        );

        self::assertSame(['APPLE-NU'], $this->connection()->fetchFirstColumn('SELECT external_id FROM workout'));
    }

    /**
     * Deux séances qui s'enchaînent ne se chevauchent pas : les bornes sont exclues. Apple
     * produit trois workouts d'affilée sans demander la permission à personne, et le
     * cooldown est parti pour de bon.
     */
    public function testTwoBackToBackSessionsAreBothCredited(): void
    {
        $bob = $this->openAccount();

        $this->import($bob, [
            self::candidate(externalId: 'HK-ECHAUFFEMENT', daysAgo: 2, at: '07:00:00', durationSeconds: 900),
            self::candidate(externalId: 'HK-SEANCE', daysAgo: 2, at: '07:15:00', durationSeconds: 1800),
        ]);

        self::assertSame(2, $this->countWorkouts());
        self::assertSame(2, $this->ledgerSize());
    }

    /**
     * Sous le plancher, rien n'est écrit du tout : laisser au joueur une ligne d'historique
     * de douze secondes qu'il n'a pas vécue serait pire que de ne rien dire.
     */
    public function testAFalseStartIsNotEvenStored(): void
    {
        $bob = $this->openAccount();

        $response = $this->import($bob, [self::candidate(daysAgo: 2, durationSeconds: 12)]);

        $body = self::decode($response);
        self::assertSame([], $body['imported']);
        self::assertSame(
            [['externalId' => 'HK-001', 'activityType' => 'running', 'reason' => 'TOO_SHORT']],
            $body['skipped'],
        );
        self::assertSame(0, $this->countWorkouts());
    }

    /**
     * Le plafond **écrête et ne rejette pas** : la montre oubliée toute la nuit rend quatre
     * heures créditées au lieu de tout perdre. Le workout, lui, garde la durée réellement
     * mesurée — on ne réécrit pas ce que la montre a vu, on décide de ce qu'on en paie.
     */
    public function testAForgottenRecordingIsClampedAndNotRejected(): void
    {
        $bob = $this->openAccount();

        $this->import($bob, [self::candidate(daysAgo: 2, durationSeconds: 12 * 3600)]);

        self::assertSame(1, $this->countWorkouts());
        self::assertSame(12 * 3600, self::asInt($this->connection()->fetchOne('SELECT duration_seconds FROM workout')));

        // Quatre heures retenues, entièrement rabotées par les rendements décroissants
        // au-delà de la deuxième heure : 60 min pleines, 30 à 60 %, 30 à 30 %, le reste à
        // rien. Le montant compte moins que le fait qu'il y en ait un.
        self::assertSame(87, $this->snapshotTotalOf($bob));
    }

    /**
     * @param list<array<string, mixed>> $workouts
     */
    private function import(Account $account, array $workouts, string $key = 'import-du-jour'): Response
    {
        return $this->post(
            '/api/workouts/import',
            ['workouts' => $workouts],
            $account->headers + ['Idempotency-Key' => $key],
        );
    }

    /**
     * Daté relativement à maintenant, parce que la fenêtre l'est : une date en dur
     * basculerait hors fenêtre le jour où on relit la suite, et le test deviendrait un
     * piège différé.
     *
     * @return array<string, mixed>
     */
    private static function candidate(
        string $externalId = 'HK-001',
        int $daysAgo = 2,
        string $at = '07:00:00',
        int $durationSeconds = 1800,
        string $source = 'APPLE_HEALTH',
        string $activityType = 'running',
        ?int $distanceMeters = null,
        ?int $calories = null,
        ?int $averageHeartRate = null,
    ): array {
        $startedAt = new DateTimeImmutable(\sprintf('-%d days', $daysAgo))
            ->setTime(...array_map(intval(...), explode(':', $at)));

        return [
            'externalId' => $externalId,
            'source' => $source,
            'activityType' => $activityType,
            'startedAt' => $startedAt->format(DateTimeInterface::ATOM),
            'endedAt' => $startedAt->modify(\sprintf('+%d seconds', $durationSeconds))->format(DateTimeInterface::ATOM),
            'distanceMeters' => $distanceMeters,
            'calories' => $calories,
            'averageHeartRate' => $averageHeartRate,
        ];
    }

    private function countWorkouts(): int
    {
        return self::asInt($this->connection()->fetchOne('SELECT COUNT(*) FROM workout'));
    }

    private function ledgerSize(): int
    {
        return self::asInt($this->connection()->fetchOne('SELECT COUNT(*) FROM xp_transaction'));
    }

    private function outboxSize(): int
    {
        return self::asInt($this->connection()->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
    }

    private function snapshotTotalOf(Account $account): int
    {
        return self::asInt($this->connection()->fetchOne(
            'SELECT COALESCE(MAX(total_xp), 0) FROM progression_snapshot WHERE user_id = :id',
            ['id' => $account->id->toRfc4122()],
        ));
    }

    private static function asInt(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }
}
