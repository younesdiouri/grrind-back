<?php

declare(strict_types=1);

namespace App\Tests\Training;

use App\Progression\Application\GrantXp;
use App\Progression\Application\GrantXpHandler;
use App\Progression\Domain\ProgressionSnapshot;
use App\Progression\Infrastructure\Doctrine\ProgressionSnapshotRepository;
use App\Shared\Domain\Activity\Discipline;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Workouts;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * La timeline contre la vraie API : ce que le client reçoit et joue.
 *
 * {@see SyncSummaryPayloadTest} tient la forme sur un payload construit à la main ; celui-ci
 * prouve que la vraie transaction la produit, avec des montants qui viennent du moteur et
 * non d'un objet posé pour le test.
 */
final class ImportSyncSummaryTest extends ApiTestCase
{
    use Workouts;

    private GrantXpHandler $grantXp;
    private ProgressionSnapshotRepository $snapshots;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $grantXp = self::getContainer()->get(GrantXpHandler::class);
        self::assertInstanceOf(GrantXpHandler::class, $grantXp);
        $this->grantXp = $grantXp;

        $snapshots = self::getContainer()->get(ProgressionSnapshotRepository::class);
        self::assertInstanceOf(ProgressionSnapshotRepository::class, $snapshots);
        $this->snapshots = $snapshots;

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
    }

    /**
     * **Le test qui porte le ticket.** Un joueur revient après dix jours d'absence : il
     * doit voir sa progression se jouer workout par workout, sur une barre continue, et pas
     * lire « tu es passé de 10 à 15 ».
     *
     * La continuité se vérifie ici : le `totalXp` d'arrivée de chaque workout est le point
     * de départ du suivant, **sans un seul recalcul côté client**.
     */
    public function testTheTimelineIsContinuousFromOneWorkoutToTheNext(): void
    {
        $bob = $this->openAccount();

        $body = self::decode($this->import($bob, [
            self::candidate(externalId: 'HK-1', daysAgo: 9),
            self::candidate(externalId: 'HK-2', daysAgo: 6),
            self::candidate(externalId: 'HK-3', daysAgo: 3),
        ]));

        self::assertIsArray($body['imported']);
        self::assertCount(3, $body['imported']);

        $arrival = 0;

        foreach ($body['imported'] as $reward) {
            self::assertIsArray($reward);
            $level = $reward['level'];
            $xp = $reward['xp'];
            self::assertIsArray($level);
            self::assertIsArray($xp);

            self::assertIsInt($level['totalXp']);
            self::assertIsInt($xp['awarded']);

            // Le départ de celui-ci est l'arrivée du précédent : la barre ne saute jamais.
            self::assertSame($arrival, $level['totalXp'] - $xp['awarded']);
            $arrival = $level['totalXp'];
        }

        self::assertSame(90, $arrival);
    }

    /**
     * **Le garde-fou du #162.** Les cinq caractéristiques annoncées dans le payload ne sont
     * pas de la mise en forme : elles doivent égaler exactement ce que le vrai snapshot a
     * gagné, sur chacune des cinq valeurs — Vitality comprise, qui n'a pourtant pas de
     * `gained` puisqu'elle n'en reçoit jamais.
     *
     * Le joueur part d'un état non trivial (un premier crédit direct au ledger) plutôt que
     * de zéro partout : sans quoi l'invariant se vérifierait sur un cas particulier — un
     * compte tout neuf où « avant » et « gagné » se confondent.
     */
    public function testTheAttributeGainsInThePayloadEqualTheDifferenceOfTheRealSnapshots(): void
    {
        $bob = $this->openAccount();

        ($this->grantXp)(new GrantXp($bob->id, Uuid::v7(), Discipline::Strength, 3600, new DateTimeImmutable('-10 days')));

        $before = $this->snapshots->ofPlayer($bob->id);
        self::assertInstanceOf(ProgressionSnapshot::class, $before);
        $attributesBefore = $before->attributes();
        $vitalityBefore = $before->vitality();

        $body = self::decode($this->import($bob, [self::candidate(daysAgo: 3)]));
        self::assertIsArray($body['imported']);
        self::assertCount(1, $body['imported']);

        $reward = $body['imported'][0];
        self::assertIsArray($reward);
        $attributes = $reward['attributes'];
        self::assertIsArray($attributes);

        // Sans ce `clear()`, `ofPlayer()` rendrait l'entité déjà chargée par `$before` —
        // le même objet PHP, pas une relecture de ce que l'import vient d'écrire.
        $this->entityManager->clear();
        $after = $this->snapshots->ofPlayer($bob->id);
        self::assertInstanceOf(ProgressionSnapshot::class, $after);
        $attributesAfter = $after->attributes();

        $expected = [
            'strength' => [$attributesBefore->strength, $attributesAfter->strength],
            'endurance' => [$attributesBefore->endurance, $attributesAfter->endurance],
            'mobility' => [$attributesBefore->mobility, $attributesAfter->mobility],
            'dexterity' => [$attributesBefore->dexterity, $attributesAfter->dexterity],
        ];

        foreach ($expected as $key => [$expectedBefore, $expectedAfter]) {
            $gauge = $attributes[$key];
            self::assertIsArray($gauge);
            self::assertIsInt($gauge['gained']);
            self::assertIsInt($gauge['before']);
            self::assertIsInt($gauge['after']);

            self::assertSame($expectedBefore, $gauge['before'], "$key : le payload doit repartir d'où le snapshot en était.");
            self::assertSame($expectedAfter, $gauge['after'], "$key : le palier d'arrivée annoncé doit être celui que le snapshot porte réellement.");
            self::assertSame($gauge['after'] - $gauge['before'], $gauge['gained'], "$key : le gain annoncé doit égaler la différence des deux snapshots.");
        }

        $vitality = $attributes['vitality'];
        self::assertIsArray($vitality);
        self::assertSame($vitalityBefore, $vitality['before']);
        self::assertSame($after->vitality(), $vitality['after']);
    }

    /**
     * **Le deuxième garde-fou du #162.** Comme la barre de niveau, les cinq jauges
     * s'enchaînent sans trou : l'arrivée de l'une est le départ de la suivante, sur toute la
     * timeline — c'est ce qui permet au client d'animer trois workouts d'affilée sans un
     * seul recalcul.
     */
    public function testTheAttributeAndVitalityGaugesChainFromOneWorkoutToTheNext(): void
    {
        $bob = $this->openAccount();

        $body = self::decode($this->import($bob, [
            self::candidate(externalId: 'HK-1', daysAgo: 9),
            self::candidate(externalId: 'HK-2', daysAgo: 6),
            self::candidate(externalId: 'HK-3', daysAgo: 3),
        ]));

        self::assertIsArray($body['imported']);
        self::assertCount(3, $body['imported']);

        $previousAttributes = null;
        $previousLevelAfter = null;

        foreach ($body['imported'] as $reward) {
            self::assertIsArray($reward);
            $attributes = $reward['attributes'];
            $level = $reward['level'];
            self::assertIsArray($attributes);
            self::assertIsArray($level);

            if (null !== $previousAttributes) {
                self::assertSame($previousLevelAfter, $level['before'], 'Le niveau doit lui aussi s\'enchaîner sans trou.');

                foreach (['strength', 'endurance', 'mobility', 'dexterity', 'vitality'] as $key) {
                    $gauge = $attributes[$key];
                    $previousGauge = $previousAttributes[$key];
                    self::assertIsArray($gauge);
                    self::assertIsArray($previousGauge);

                    self::assertSame(
                        $previousGauge['after'],
                        $gauge['before'],
                        "$key : l'après de l'un doit être l'avant du suivant.",
                    );
                }
            }

            $previousAttributes = $attributes;
            $previousLevelAfter = $level['after'];
        }
    }

    /**
     * **Le ticket #166.** Les trois nouvelles disciplines traversent l'import comme les
     * sept de la V1 : la montre les mesure, `ActivityTypeMap` les traduit, et la séance
     * portée par le `RewardSummary` affiche la bonne discipline.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function theThreeNewDisciplines(): iterable
    {
        yield 'football' => ['soccer', 'FOOTBALL'];
        yield 'sport de salle' => ['basketball', 'COURT_SPORTS'];
        yield 'raquette' => ['tennis', 'RACKET_SPORTS'];
    }

    #[DataProvider('theThreeNewDisciplines')]
    public function testANewDisciplineTraversesTheImport(string $activityType, string $expectedDiscipline): void
    {
        $bob = $this->openAccount();

        $body = self::decode($this->import($bob, [self::candidate(activityType: $activityType)]));

        self::assertIsArray($body['imported']);
        self::assertCount(1, $body['imported']);
        $reward = $body['imported'][0];
        self::assertIsArray($reward);
        self::assertIsArray($reward['session']);
        self::assertSame($expectedDiscipline, $reward['session']['discipline']);
    }

    /**
     * **Le garde-fou du ticket.** Sans ces trois disciplines, Dexterity ne dépassait jamais
     * 30 % d'une séance. `RACKET_SPORTS` lui donne 55 % à la table de répartition — la
     * preuve se fait sur le vrai payload, pas sur la table de configuration seule, pour
     * couvrir aussi la méthode du plus fort reste.
     */
    public function testDexterityExceedsHalfOfASessionOnARacketSport(): void
    {
        $bob = $this->openAccount();

        $body = self::decode($this->import($bob, [self::candidate(activityType: 'tennis')]));

        self::assertIsArray($body['imported']);
        $reward = $body['imported'][0];
        self::assertIsArray($reward);
        $xp = $reward['xp'];
        $attributes = $reward['attributes'];
        self::assertIsArray($xp);
        self::assertIsArray($attributes);
        self::assertIsInt($xp['awarded']);
        $dexterity = $attributes['dexterity'];
        self::assertIsArray($dexterity);
        self::assertIsInt($dexterity['gained']);

        self::assertGreaterThan($xp['awarded'] / 2, $dexterity['gained']);
    }

    /**
     * L'ordre est **chronologique**, celui du crédit — pas celui du lot. Le client joue la
     * liste de haut en bas sans la trier.
     */
    public function testTheTimelineIsChronologicalWhateverOrderTheClientSent(): void
    {
        $bob = $this->openAccount();

        $body = self::decode($this->import($bob, [
            self::candidate(externalId: 'HK-MERCREDI', daysAgo: 3),
            self::candidate(externalId: 'HK-LUNDI', daysAgo: 5),
            self::candidate(externalId: 'HK-MARDI', daysAgo: 4),
        ]));

        self::assertIsArray($body['imported']);

        // Les dates de début, telles que le serveur les rend. L'`externalId` n'est pas dans
        // la ressource — c'est un identifiant de fournisseur, pas un champ de contrat.
        $startedAt = array_map(
            static function (mixed $reward): string {
                self::assertIsArray($reward);
                self::assertIsArray($reward['session']);
                self::assertIsString($reward['session']['startedAt']);

                return $reward['session']['startedAt'];
            },
            $body['imported'],
        );

        $chronological = $startedAt;
        sort($chronological);

        self::assertSame($chronological, $startedAt, 'Le client joue la liste de haut en bas : c\'est au serveur de la trier.');
    }

    /**
     * `totals` sert l'écran de résumé et le joueur qui saute l'animation. Il ne doit jamais
     * pouvoir diverger d'`imported`, et il ne le peut pas : il en est dérivé.
     */
    public function testTheTotalsAgreeWithTheTimelineTheySummarise(): void
    {
        $bob = $this->openAccount();

        $body = self::decode($this->import($bob, [
            self::candidate(externalId: 'HK-1', daysAgo: 9),
            self::candidate(externalId: 'HK-2', daysAgo: 6),
        ]));

        $totals = $body['totals'];
        self::assertIsArray($totals);
        self::assertIsArray($body['imported']);

        $last = $body['imported'][1];
        self::assertIsArray($last);
        self::assertIsArray($last['level']);

        self::assertSame(2, $totals['workoutCount']);
        self::assertSame(60, $totals['xpAwarded']);
        self::assertSame(0, $totals['xpBefore']);
        self::assertSame($last['level']['totalXp'], $totals['xpAfter']);
        self::assertSame($last['level']['after'], $totals['levelAfter']);
    }

    /**
     * Une synchronisation sans rien de neuf reste un **succès**, et c'est le cas le plus
     * fréquent de tous. Elle n'a simplement pas d'écran de résumé à montrer.
     */
    public function testASyncWithNothingNewIsASuccessWithoutTotals(): void
    {
        $bob = $this->openAccount();
        $lot = [self::candidate(daysAgo: 3)];
        $this->import($bob, $lot);

        $response = $this->import($bob, $lot, key: 'seconde-synchro');
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $body = self::decode($response);
        self::assertSame([], $body['imported']);
        self::assertNull($body['totals']);
        self::assertIsArray($body['skipped']);
        self::assertCount(1, $body['skipped']);
    }

    /**
     * `syncedAt` est le seul instant du payload qui vienne du serveur, et le client s'en
     * sert comme repère de dernière synchronisation réussie.
     */
    public function testTheSyncCarriesTheServerClockAndTheRulesetItRanUnder(): void
    {
        $bob = $this->openAccount();

        $body = self::decode($this->import($bob, [self::candidate(daysAgo: 3)]));

        self::assertIsString($body['syncedAt']);
        self::assertEqualsWithDelta(
            time(),
            new DateTimeImmutable($body['syncedAt'])->getTimestamp(),
            5,
        );

        self::assertIsString($body['rulesetVersion']);
        self::assertMatchesRegularExpression('/^v1-[0-9a-f]{12}$/', $body['rulesetVersion']);
    }

    /**
     * Le rejeu rend la timeline d'origine à l'identique — c'est exactement ce pour quoi
     * `Idempotency-Key` existe ici : sans elle, le client rejouerait, tout serait écarté
     * comme doublon, et il recevrait une synchronisation vide au lieu de *sa* mise en
     * scène. L'XP serait juste, l'animation perdue.
     */
    public function testReplayingGivesBackTheWholeTimelineAndNotAnEmptySync(): void
    {
        $bob = $this->openAccount();
        $lot = [self::candidate(externalId: 'HK-1', daysAgo: 9), self::candidate(externalId: 'HK-2', daysAgo: 6)];

        $first = $this->import($bob, $lot);
        $replay = $this->import($bob, $lot);

        self::assertSame($first->getContent(), $replay->getContent());
        self::assertIsArray(self::decode($replay)['imported']);
        self::assertCount(2, self::decode($replay)['imported']);
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
     * Daté relativement à maintenant, parce que la fenêtre d'import l'est : une date en dur
     * basculerait hors fenêtre le jour où on relit la suite.
     *
     * @return array<string, mixed>
     */
    private static function candidate(string $externalId = 'HK-001', int $daysAgo = 3, string $activityType = 'running'): array
    {
        $startedAt = new DateTimeImmutable(\sprintf('-%d days', $daysAgo))->setTime(7, 0);

        return [
            'externalId' => $externalId,
            'source' => 'APPLE_HEALTH',
            'activityType' => $activityType,
            'startedAt' => $startedAt->format(DateTimeInterface::ATOM),
            'endedAt' => $startedAt->modify('+1800 seconds')->format(DateTimeInterface::ATOM),
        ];
    }
}
