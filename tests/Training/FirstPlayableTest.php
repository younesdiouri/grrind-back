<?php

declare(strict_types=1);

namespace App\Tests\Training;

use App\Progression\Application\GrantXp;
use App\Progression\Application\GrantXpHandler;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\UI\Http\IdempotencyListener;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\ProgrammableModifiers;
use App\Tests\Support\Workouts;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * **Le premier jouable, de bout en bout.** Un joueur déjà niveau 10 rouvre l'app après dix
 * jours d'absence, et sa montre a neuf séances à raconter : cinq qui comptent, un doublon,
 * une archive, un chevauchement, une activité qu'on ne traduit pas.
 *
 * C'est le scénario du produit, pas un cas limite — et c'est pour ça qu'il a son test à lui.
 * Chacune de ses briques est déjà couverte ailleurs ; ce qui ne l'est nulle part, c'est
 * qu'elles tiennent **ensemble** sur un lot réaliste, en une transaction, et que le résultat
 * soit jouable.
 *
 * Le compte de départ est monté par le vrai chemin de crédit et non par un `INSERT` : un
 * joueur de niveau 10 dont le ledger serait faux ferait passer ce test pour de mauvaises
 * raisons.
 */
final class FirstPlayableTest extends ApiTestCase
{
    use Workouts;
    /** Vingt jours d'une heure de course et dix kilomètres : 160 XP par jour, 3 200 au total. */
    private const int SEEDED_DAYS = 20;

    private GrantXpHandler $grantXp;

    protected function setUp(): void
    {
        parent::setUp();

        $grantXp = self::getContainer()->get(GrantXpHandler::class);
        self::assertInstanceOf(GrantXpHandler::class, $grantXp);
        $this->grantXp = $grantXp;
    }

    /**
     * Cinq crédits, quatre refus **nommés**. « 4 workouts ignorés » ne dirait rien ; le
     * client doit pouvoir expliquer chacun.
     */
    public function testTheWholeBatchIsArbitratedAndEveryRefusalIsNamed(): void
    {
        $bob = $this->seasonedPlayer();

        $body = self::decode($this->import($bob, self::theTenDaysOfAbsence()));

        self::assertIsArray($body['imported']);
        self::assertCount(5, $body['imported']);

        self::assertSame(
            [
                // L'ordre est celui des refus, donc celui de la pratique : le doublon tombe
                // à la place de HK-1, l'archive en dernier parce qu'elle n'est écartée
                // qu'une fois écrite.
                'HK-1' => 'ALREADY_IMPORTED',
                'HK-CURLING' => 'UNSUPPORTED_ACTIVITY',
                'HK-CHEVAUCHE' => 'OVERLAPS',
                'HK-ARCHIVE' => 'OUT_OF_WINDOW',
            ],
            self::refusalsOf($body),
        );

        // Six lignes en base : les cinq créditées, plus l'archive — conservée sans être
        // payée. Les trois autres refus n'écrivent rien.
        self::assertSame(6, $this->countWorkouts());
        self::assertSame(self::SEEDED_DAYS + 5, $this->ledgerSize());
    }

    /**
     * La timeline est **continue** : le palier d'arrivée de chaque workout est le palier de
     * départ du suivant, sans trou. C'est la condition pour que le client enchaîne les
     * animations sans un seul recalcul — et c'est ce que le #79 a rendu possible.
     */
    public function testTheTimelineChainsWithoutAGap(): void
    {
        $bob = $this->seasonedPlayer();

        $body = self::decode($this->import($bob, self::theTenDaysOfAbsence()));
        self::assertIsArray($body['imported']);

        $previous = null;

        foreach ($body['imported'] as $entry) {
            self::assertIsArray($entry);
            $level = $entry['level'];
            $xp = $entry['xp'];
            self::assertIsArray($level);
            self::assertIsArray($xp);
            self::assertIsInt($level['before']);
            self::assertIsInt($level['after']);
            self::assertIsInt($level['totalXp']);
            self::assertIsInt($xp['awarded']);

            if (null !== $previous) {
                self::assertSame($previous['after'], $level['before'], 'Le palier d\'arrivée de l\'un est celui de départ du suivant.');
                self::assertSame($previous['totalXp'], $level['totalXp'] - $xp['awarded']);
            }

            $previous = $level;
        }

        self::assertNotNull($previous);
        self::assertGreaterThanOrEqual(10, $previous['after'], 'Le joueur part du niveau 10 et ne redescend pas.');

        // `totals` résume la même chose sans jamais pouvoir en diverger : il en est dérivé.
        $totals = $body['totals'];
        self::assertIsArray($totals);
        self::assertSame(10, $totals['levelBefore']);
        self::assertSame($previous['after'], $totals['levelAfter']);
        self::assertSame($previous['totalXp'], $totals['xpAfter']);
        self::assertSame(5, $totals['workoutCount']);
    }

    /**
     * Le même lot envoyé mélangé donne **le même ledger**. C'est ce qui rend l'ordre
     * chronologique non cosmétique : sans lui, les rendements décroissants s'appliqueraient
     * dans l'ordre où le client a empilé ses pages.
     */
    public function testAShuffledBatchGivesTheSameLedger(): void
    {
        $ordered = $this->seasonedPlayer();
        $shuffled = $this->seasonedPlayer('alice@grrind.app', 'Alice');

        $lot = self::theTenDaysOfAbsence();

        $this->import($ordered, $lot);
        $this->import($shuffled, array_reverse($lot));

        self::assertSame($this->totalXpOf($ordered), $this->totalXpOf($shuffled));
        self::assertSame(
            $this->creditedAmountsOf($ordered),
            $this->creditedAmountsOf($shuffled),
            'Les montants, dans l\'ordre chronologique, doivent être identiques workout par workout.',
        );
    }

    /**
     * Une panne au milieu du lot ne laisse **rien** : ni workout, ni XP, ni ligne d'outbox.
     * Le joueur retente et retrouve sa synchronisation entière, plutôt que la moitié d'une
     * récompense qu'il ne pourra jamais compléter.
     */
    public function testAFailureMidBatchLeavesTheAccountExactlyAsItWas(): void
    {
        $bob = $this->seasonedPlayer();

        $workoutsBefore = $this->countWorkouts();
        $ledgerBefore = $this->ledgerSize();
        $totalBefore = $this->totalXpOf($bob);
        $outboxBefore = $this->outboxSize();

        ProgrammableModifiers::failAfter(3);
        $response = $this->import($bob, self::theTenDaysOfAbsence());

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        self::assertSame($workoutsBefore, $this->countWorkouts());
        self::assertSame($ledgerBefore, $this->ledgerSize());
        self::assertSame($totalBefore, $this->totalXpOf($bob));
        self::assertSame($outboxBefore, $this->outboxSize());
    }

    /**
     * Le rejeu rend la synchronisation d'origine à l'identique, sans rien réexécuter.
     * L'unicité par `externalId` ne sait pas faire ça : sans l'idempotence, le client
     * recevrait une synchronisation vide au lieu de *sa* mise en scène — l'XP serait juste,
     * l'animation perdue.
     */
    public function testReplayingTheSameKeyGivesBackTheSameSyncSummary(): void
    {
        $bob = $this->seasonedPlayer();
        $lot = self::theTenDaysOfAbsence();

        $first = $this->import($bob, $lot);
        $replay = $this->import($bob, $lot);

        self::assertSame('true', $replay->headers->get(IdempotencyListener::REPLAY_HEADER));
        self::assertSame($first->getContent(), $replay->getContent());
        self::assertSame(self::SEEDED_DAYS + 5, $this->ledgerSize());
        // 10 et non 5 depuis #128 : `WorkoutImported` et `WorkoutCredited` publient chacun
        // leur fait pour les cinq workouts crédités du lot.
        self::assertSame(10, $this->outboxSize());
    }

    /**
     * **Le cas à ne jamais rater** : l'utilisateur qui rouvre l'app trois fois dans la
     * journée. Clé neuve, même contenu — donc pas de rejeu — et pourtant rien n'est
     * recrédité. C'est l'unicité `(user, source, externalId)` qui tient, pas un cache.
     */
    public function testASecondImportWithAFreshKeyCreditsNothing(): void
    {
        $bob = $this->seasonedPlayer();
        $lot = self::theTenDaysOfAbsence();

        $this->import($bob, $lot);
        $totalAfterFirst = $this->totalXpOf($bob);

        $body = self::decode($this->import($bob, $lot, key: 'troisieme-ouverture-de-la-journee'));

        self::assertSame([], $body['imported']);
        self::assertNull($body['totals'], 'Rien de crédité : il n\'y a pas d\'état d\'arrivée à résumer.');
        self::assertSame($totalAfterFirst, $this->totalXpOf($bob));
        self::assertSame(self::SEEDED_DAYS + 5, $this->ledgerSize());

        // Les six workouts en base repartent en `ALREADY_IMPORTED` — l'archive comprise,
        // elle est connue du serveur même si elle n'a rien rapporté. Le curling n'est
        // toujours pas un sport, et le chevauchement l'est toujours.
        $refusals = self::refusalsOf($body);
        self::assertSame('UNSUPPORTED_ACTIVITY', $refusals['HK-CURLING']);
        self::assertSame('ALREADY_IMPORTED', $refusals['HK-ARCHIVE']);
        self::assertSame('ALREADY_IMPORTED', $refusals['HK-1']);
    }

    /**
     * L'historique sert ensuite les six lignes, archive comprise, dans l'ordre de la
     * pratique — et `sync-state` donne au client de quoi ne demander à HealthKit que ce qui
     * a bougé depuis.
     */
    public function testTheHistoryAndTheSyncStateAgreeWithWhatWasImported(): void
    {
        $bob = $this->seasonedPlayer();
        $this->import($bob, self::theTenDaysOfAbsence());

        $history = self::decode($this->get('/api/workouts?limit=50', $bob->headers));
        self::assertIsArray($history['workouts']);
        self::assertCount(6, $history['workouts']);

        $startedAt = array_map(
            static function (mixed $workout): string {
                self::assertIsArray($workout);
                self::assertIsString($workout['startedAt']);

                return $workout['startedAt'];
            },
            $history['workouts'],
        );

        $chronological = $startedAt;
        rsort($chronological);
        self::assertSame($chronological, $startedAt);

        $syncState = self::decode($this->get('/api/workouts/sync-state', $bob->headers));
        self::assertSame(30, $syncState['importWindowDays']);
        self::assertIsString($syncState['lastImportedAt']);

        // La borne est la fin du workout le plus récent en base — celui d'hier.
        self::assertGreaterThan(
            new DateTimeImmutable('-2 days')->getTimestamp(),
            new DateTimeImmutable($syncState['lastImportedAt'])->getTimestamp(),
        );
    }

    /**
     * Un joueur de niveau 10, monté par le vrai chemin : vingt journées d'une heure de
     * course et dix kilomètres, chacune sur son jour pour qu'aucun rendement décroissant ne
     * s'en mêle. 160 XP par jour, 3 200 au total — le seuil du niveau 10 est à 3 060.
     */
    private function seasonedPlayer(string $email = 'bob@grrind.app', string $displayName = 'Bob'): Account
    {
        $account = $this->openAccount($email, $displayName);

        for ($day = self::SEEDED_DAYS + 30; $day > 30; --$day) {
            ($this->grantXp)(new GrantXp(
                $account->id,
                Uuid::v7(),
                Discipline::Running,
                3600,
                new DateTimeImmutable(\sprintf('-%d days', $day)),
                distanceMeters: 10_000,
            ));
        }

        self::assertSame(3200, $this->totalXpOf($account));

        return $account;
    }

    /**
     * Neuf séances : cinq qui comptent, et les quatre façons de ne pas compter.
     *
     * @return list<array<string, mixed>>
     */
    private static function theTenDaysOfAbsence(): array
    {
        return [
            self::candidate('HK-1', 9, '07:00:00', 2700, 'running', distanceMeters: 8_000),
            self::candidate('HK-2', 7, '18:00:00', 3600, 'cycling', distanceMeters: 25_000),
            self::candidate('HK-3', 5, '12:30:00', 2400, 'traditionalStrengthTraining'),
            self::candidate('HK-4', 3, '07:15:00', 1800, 'running', distanceMeters: 5_000),
            self::candidate('HK-5', 1, '09:00:00', 5400, 'hiking', distanceMeters: 8_000, elevationGainMeters: 400),

            // Rigoureusement la même séance que HK-1, dans le même lot : un client qui a
            // concaténé deux pages de HealthKit sans les dédoublonner.
            self::candidate('HK-1', 9, '07:00:00', 2700, 'running', distanceMeters: 8_000),

            // Trois ans d'archives, dont une séance : conservée, jamais payée.
            self::candidate('HK-ARCHIVE', 400, '10:00:00', 3600, 'running', distanceMeters: 12_000),

            // La même sortie que HK-2, vue par la seconde application du téléphone.
            self::candidate('HK-CHEVAUCHE', 7, '18:00:31', 3540, 'running'),

            self::candidate('HK-CURLING', 4, '20:00:00', 3600, 'curling'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function candidate(
        string $externalId,
        int $daysAgo,
        string $at,
        int $durationSeconds,
        string $activityType,
        ?int $distanceMeters = null,
        ?int $elevationGainMeters = null,
    ): array {
        $startedAt = new DateTimeImmutable(\sprintf('-%d days', $daysAgo))
            ->setTime(...array_map(intval(...), explode(':', $at)));

        return [
            'externalId' => $externalId,
            'source' => 'APPLE_HEALTH',
            'activityType' => $activityType,
            'startedAt' => $startedAt->format(DateTimeInterface::ATOM),
            'endedAt' => $startedAt->modify(\sprintf('+%d seconds', $durationSeconds))->format(DateTimeInterface::ATOM),
            'distanceMeters' => $distanceMeters,
            'elevationGainMeters' => $elevationGainMeters,
        ];
    }

    /**
     * @param array<mixed> $body
     *
     * @return array<string, string>
     */
    private static function refusalsOf(array $body): array
    {
        self::assertIsArray($body['skipped']);

        $refusals = [];

        foreach ($body['skipped'] as $skipped) {
            self::assertIsArray($skipped);
            self::assertIsString($skipped['externalId']);
            self::assertIsString($skipped['reason']);

            $refusals[$skipped['externalId']] = $skipped['reason'];
        }

        return $refusals;
    }

    /**
     * @param list<array<string, mixed>> $workouts
     */
    private function import(Account $account, array $workouts, string $key = 'retour-de-vacances'): Response
    {
        return $this->post(
            '/api/workouts/import',
            ['workouts' => $workouts],
            $account->headers + ['Idempotency-Key' => $key],
        );
    }

    /**
     * @return list<int>
     */
    private function creditedAmountsOf(Account $account): array
    {
        return array_map(
            static fn (mixed $amount): int => self::asInt($amount),
            $this->connection()->fetchFirstColumn(
                'SELECT amount FROM xp_transaction WHERE user_id = :id ORDER BY occurred_at, id',
                ['id' => $account->id->toRfc4122()],
            ),
        );
    }

    private function totalXpOf(Account $account): int
    {
        return self::asInt($this->connection()->fetchOne(
            'SELECT COALESCE(SUM(amount), 0) FROM xp_transaction WHERE user_id = :id',
            ['id' => $account->id->toRfc4122()],
        ));
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

    private static function asInt(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }
}
