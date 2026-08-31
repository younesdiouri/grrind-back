<?php

declare(strict_types=1);

namespace App\Tests\Training;

use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Modifier\Modifier;
use App\Shared\Domain\Modifier\ModifierSource;
use App\Shared\Domain\Modifier\ModifierType;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\ProgrammableModifiers;
use App\Tests\Support\Workouts;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * La transaction d'import contre la vraie base : un seul COMMIT, ou rien.
 *
 * Ce qui se démontre ici ne se démontre nulle part ailleurs. Les suites de `Progression`
 * prouvent que le crédit est **juste** ; celles-ci prouvent qu'il est **lié au workout** —
 * que l'import écrit au ledger dans le même COMMIT, et qu'un échec en aval ne laisse ni
 * workout, ni XP, ni événement dans l'outbox.
 *
 * L'échec est provoqué par la base elle-même plutôt que par un service de test qui lèverait
 * sur commande : `uniq_xp_transaction_source_reason` est précisément le garde-fou qui
 * arbitre deux crédits simultanés, et un double le remplacerait par une mise en scène.
 */
final class ImportTransactionTest extends ApiTestCase
{
    use Workouts;

    public function testAnImportCreditsTheLedgerInTheSameCommit(): void
    {
        $bob = $this->openAccount();

        $response = $this->import($bob, [self::candidate(durationSeconds: 1800)]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        // Une demi-heure, une minute pour un point, sans aucun modificateur actif ni
        // rendement décroissant déclenché : le socle seul, et il est au ledger.
        self::assertSame(30, $this->ledgerTotalOf($bob));

        // Le snapshot est reprojeté dans la foulée — c'est ce que le client lira ensuite sur
        // `GET /api/progression`, sans attendre un consommateur de l'outbox.
        self::assertSame(30, $this->snapshotTotalOf($bob));
    }

    /**
     * Dix workouts, un seul COMMIT. Le verrou est pris une fois — un verrou de ligne est
     * ré-entrant dans une transaction — et tenu jusqu'au bout.
     */
    public function testABatchIsCreditedWorkoutByWorkoutInOneTransaction(): void
    {
        $bob = $this->openAccount();

        $this->import($bob, [
            self::candidate(externalId: 'HK-1', startedAt: self::daysAgo(7)->format(DateTimeInterface::ATOM), durationSeconds: 1800),
            self::candidate(externalId: 'HK-2', startedAt: self::daysAgo(6)->format(DateTimeInterface::ATOM), durationSeconds: 1800),
            self::candidate(externalId: 'HK-3', startedAt: self::daysAgo(5)->format(DateTimeInterface::ATOM), durationSeconds: 1800),
        ]);

        // Trois journées distinctes, donc aucun rendement décroissant : 30 chacune.
        self::assertSame(3, $this->ledgerSize());
        self::assertSame(90, $this->snapshotTotalOf($bob));
    }

    /**
     * **Le test qui porte le #190.** Un modificateur borné dans le temps ne bonifie que les
     * séances qui tombent dedans, et c'est ici que ça se démontre : nulle part ailleurs
     * deux dates différentes ne traversent la même transaction.
     *
     * Le rattrapage d'un joueur absent est le cas nominal, pas l'exception : dix jours en
     * une synchronisation, dont une seule séance postérieure à la Risāla de sa guilde. Si
     * les modificateurs se résolvaient au moment du crédit — « ici et maintenant » —, les
     * deux séances seraient bonifiées et le ledger porterait un montant que rien ne
     * pourrait plus justifier.
     */
    public function testABoundedModifierOnlyBonusesTheSessionsThatFallInsideIt(): void
    {
        $bob = $this->openAccount();

        $revealedAt = new DateTimeImmutable('-3 days');
        ProgrammableModifiers::grantFrom(
            $revealedAt,
            new Modifier(ModifierType::XpMultiplier, 150, ModifierSource::Guild, Discipline::Running),
        );

        $this->import($bob, [
            self::candidate(externalId: 'HK-AVANT', startedAt: self::daysAgo(10)->format(DateTimeInterface::ATOM), durationSeconds: 1800),
            self::candidate(externalId: 'HK-APRES', startedAt: self::daysAgo(1)->format(DateTimeInterface::ATOM), durationSeconds: 1800),
        ]);

        // Deux journées distinctes, donc aucun rendement décroissant : 30 chacune, et
        // +45 sur la seule qui a eu lieu après la révélation.
        self::assertSame(105, $this->ledgerTotalOf($bob));
    }

    /**
     * Le pendant du test précédent, et il n'est pas redondant : c'est la **ligne** du détail
     * qui remonte jusqu'au client, pas seulement le total. Sans elle, le montant serait juste
     * et l'animation muette.
     */
    public function testTheBonusedSessionCarriesItsGuildLineToTheClient(): void
    {
        $bob = $this->openAccount();

        ProgrammableModifiers::grantFrom(
            new DateTimeImmutable('-3 days'),
            new Modifier(ModifierType::XpMultiplier, 150, ModifierSource::Guild, Discipline::Running),
        );

        $body = self::decode($this->import($bob, [
            self::candidate(externalId: 'HK-AVANT', startedAt: self::daysAgo(10)->format(DateTimeInterface::ATOM), durationSeconds: 1800),
            self::candidate(externalId: 'HK-APRES', startedAt: self::daysAgo(1)->format(DateTimeInterface::ATOM), durationSeconds: 1800),
        ]));

        self::assertIsArray($body['imported']);
        self::assertSame(
            [
                ['BASE'],
                ['BASE', 'GUILD'],
            ],
            array_map(self::breakdownSourcesOf(...), $body['imported']),
        );
    }

    /**
     * **Le test qui porte le ticket.** Les rendements décroissants se calculent sur ce que
     * le joueur a déjà fait ce jour-là, donc la charge du jour doit être relue à chaque
     * itération — en voyant les workouts crédités plus tôt dans la **même boucle**, pas
     * seulement ce qui était en base au BEGIN.
     *
     * Deux heures de course le même jour, en trois séances : la première heure pleine, la
     * demi-heure suivante à 60 %, la dernière à 30 %. Si la boucle relisait la même charge à
     * chaque fois, les trois vaudraient leur plein et le total serait 120.
     */
    public function testTheDailyLoadSeesTheWorkoutsCreditedEarlierInTheSameBatch(): void
    {
        $bob = $this->openAccount();

        $this->import($bob, [
            self::candidate(externalId: 'HK-MATIN', startedAt: self::daysAgo(5, '06:00:00')->format(DateTimeInterface::ATOM), durationSeconds: 3600),
            self::candidate(externalId: 'HK-MIDI', startedAt: self::daysAgo(5, '12:00:00')->format(DateTimeInterface::ATOM), durationSeconds: 1800),
            self::candidate(externalId: 'HK-SOIR', startedAt: self::daysAgo(5, '18:00:00')->format(DateTimeInterface::ATOM), durationSeconds: 1800),
        ]);

        // 60 sur la première heure, puis 18 et 9 sur les deux demi-heures des tranches
        // 60-90 et 90-120, pondérées à 60 % et 30 %.
        self::assertSame(60 + 18 + 9, $this->snapshotTotalOf($bob));
    }

    /**
     * Le corollaire, et c'est lui qui rend l'ordre non cosmétique : le même lot envoyé à
     * l'envers doit donner **le même ledger**. Sans tri, la séance longue prendrait la
     * pleine tranche ou pas selon ce que le client a mis en premier.
     */
    public function testTheSameBatchInAnyOrderGivesTheSameTotal(): void
    {
        $bob = $this->openAccount();
        $alice = $this->openAccount('alice@grrind.app', 'Alice');

        $matin = self::candidate(externalId: 'HK-MATIN', startedAt: self::daysAgo(5, '06:00:00')->format(DateTimeInterface::ATOM), durationSeconds: 3600);
        $soir = self::candidate(externalId: 'HK-SOIR', startedAt: self::daysAgo(5, '18:00:00')->format(DateTimeInterface::ATOM), durationSeconds: 1800);

        $this->import($bob, [$matin, $soir]);
        $this->import($alice, [$soir, $matin]);

        self::assertSame($this->snapshotTotalOf($bob), $this->snapshotTotalOf($alice));
    }

    /**
     * Un workout de mardi importé vendredi compte pour **mardi**. C'est ce que le
     * renommage de `created_at` en `occurred_at` a rendu possible : daté de son écriture,
     * il se serait entassé avec les autres sur la journée de la synchronisation, où le
     * plafond quotidien en aurait écrasé la moitié.
     */
    public function testEachWorkoutIsCreditedOnTheDayItWasPractised(): void
    {
        $bob = $this->openAccount();

        // Capturées une fois : la journée attendue plus bas doit décrire exactement ce
        // qu'on vient d'envoyer, pas un second appel à `daysAgo()` qui pourrait tomber de
        // l'autre côté d'un minuit si le test s'exécutait à cheval sur deux jours.
        $lundi = self::daysAgo(7);
        $mardi = self::daysAgo(6);

        $this->import($bob, [
            self::candidate(externalId: 'HK-LUNDI', startedAt: $lundi->format(DateTimeInterface::ATOM), durationSeconds: 3600),
            self::candidate(externalId: 'HK-MARDI', startedAt: $mardi->format(DateTimeInterface::ATOM), durationSeconds: 3600),
        ]);

        // Deux heures pleines à 60, sur deux journées : aucun rendement décroissant. Sur
        // une seule journée, la seconde ne vaudrait que 27.
        self::assertSame(120, $this->snapshotTotalOf($bob));

        $days = $this->connection()->fetchFirstColumn(
            "SELECT to_char(occurred_at AT TIME ZONE 'UTC', 'YYYY-MM-DD') FROM xp_transaction ORDER BY occurred_at",
        );

        self::assertSame([$lundi->format('Y-m-d'), $mardi->format('Y-m-d')], $days);
    }

    /**
     * Le chemin d'échec, et c'est le test qui porte l'autre moitié du ticket : une panne au
     * **milieu** de la boucle ne doit rien laisser — pas même le premier workout, pourtant
     * écrit, crédité et publié avant elle.
     *
     * C'est exactement ce que dix appels séparés ne sauraient pas garantir : un échec au
     * septième laisserait six crédits en base et le joueur avec la moitié de sa récompense.
     */
    public function testAFailureMidBatchLeavesNeitherWorkoutNorXpNorEvent(): void
    {
        $bob = $this->openAccount();
        // Deux résolutions par workout crédité depuis le #226 — le crédit d'XP, puis le
        // tirage de loot — donc deux succès laissent le premier workout entièrement fait
        // avant que le troisième appel, au début du second workout, ne fasse tout échouer.
        ProgrammableModifiers::failAfter(2);

        $response = $this->import($bob, [
            self::candidate(externalId: 'HK-AVANT', startedAt: self::daysAgo(7)->format(DateTimeInterface::ATOM)),
            self::candidate(externalId: 'HK-APRES', startedAt: self::daysAgo(6)->format(DateTimeInterface::ATOM)),
        ]);

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());

        // Le rollback porte sur tout, y compris sur ce que le transport Doctrine avait déjà
        // inséré dans l'outbox — c'est ce que le pattern outbox achète.
        self::assertSame(0, $this->countWorkouts());
        self::assertSame(0, $this->ledgerSize());
        self::assertSame(0, $this->snapshotTotalOf($bob));
        self::assertSame(0, $this->outboxSize());
    }

    /**
     * L'événement part une fois par workout crédité, jamais un agrégat : le classement
     * compte des activités, pas des synchronisations.
     *
     * **Deux faits par workout crédité depuis #128** — `WorkoutImported` et
     * `WorkoutCredited` — d'où le compte à 4 et non 2 : `Training` annonce qu'un workout a
     * eu lieu, `Progression` annonce ce qu'il a rapporté, et les deux naissent ou meurent
     * ensemble avec la même transaction.
     */
    public function testOneEventPerCreditedWorkoutAndNoneForTheSkippedOnes(): void
    {
        $bob = $this->openAccount();

        $this->import($bob, [
            self::candidate(externalId: 'HK-1', startedAt: self::daysAgo(7)->format(DateTimeInterface::ATOM)),
            self::candidate(externalId: 'HK-2', startedAt: self::daysAgo(6)->format(DateTimeInterface::ATOM)),
            self::candidate(externalId: 'HK-CURLING', activityType: 'curling', startedAt: self::daysAgo(5)->format(DateTimeInterface::ATOM)),
        ]);

        self::assertSame(4, $this->outboxSize());
    }

    /**
     * Le rejeu de la clé d'idempotence rend la réponse conservée sans réexécuter la règle :
     * sans ça, l'outbox contiendrait deux fois les mêmes faits.
     *
     * 2 et non 1 depuis #128 : `WorkoutImported` et `WorkoutCredited` pour l'unique workout
     * crédité — voir la note de {@see testOneEventPerCreditedWorkoutAndNoneForTheSkippedOnes}.
     */
    public function testAReplayedRequestDoesNotPublishTwice(): void
    {
        $bob = $this->openAccount();
        $lot = [self::candidate()];

        $this->import($bob, $lot);
        $this->import($bob, $lot);

        self::assertSame(2, $this->outboxSize());
        self::assertSame(1, $this->ledgerSize());
    }

    /**
     * Un workout écarté n'annule pas le lot, et un lot entièrement écarté ne crédite rien
     * sans être un échec. Les deux phrases du ticket, dans un seul appel.
     */
    public function testASkippedWorkoutDoesNotPreventTheOthersFromBeingCredited(): void
    {
        $bob = $this->openAccount();

        $response = $this->import($bob, [
            self::candidate(externalId: 'HK-CURLING', activityType: 'curling'),
            self::candidate(externalId: 'HK-COURSE', durationSeconds: 1800),
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(30, $this->snapshotTotalOf($bob));
    }

    /**
     * @param mixed $imported une entrée de `imported`, telle que la réponse la rend
     *
     * @return list<string>
     */
    private static function breakdownSourcesOf(mixed $imported): array
    {
        self::assertIsArray($imported);
        self::assertIsArray($imported['xp']);
        self::assertIsArray($lines = $imported['xp']['breakdown']);

        return array_map(
            static function (mixed $line): string {
                self::assertIsArray($line);
                self::assertIsString($source = $line['source']);

                return $source;
            },
            array_values($lines),
        );
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
     * @return array<string, mixed>
     */
    private static function candidate(
        string $externalId = 'HK-001',
        string $activityType = 'running',
        ?string $startedAt = null,
        int $durationSeconds = 1800,
    ): array {
        // Daté relativement à l'instant du test plutôt qu'en dur (#243) — voir le
        // docblock de `Workouts::daysAgo()`.
        $startedAt ??= self::daysAgo(5)->format(DateTimeInterface::ATOM);

        return [
            'externalId' => $externalId,
            'source' => 'APPLE_HEALTH',
            'activityType' => $activityType,
            'startedAt' => $startedAt,
            'endedAt' => new DateTimeImmutable($startedAt)
                ->modify(\sprintf('+%d seconds', $durationSeconds))
                ->format(DateTimeInterface::ATOM),
        ];
    }

    private function ledgerTotalOf(Account $account): int
    {
        return self::asInt($this->connection()->fetchOne(
            'SELECT COALESCE(SUM(amount), 0) FROM xp_transaction WHERE user_id = :id',
            ['id' => $account->id->toRfc4122()],
        ));
    }

    private function snapshotTotalOf(Account $account): int
    {
        return self::asInt($this->connection()->fetchOne(
            'SELECT COALESCE(MAX(total_xp), 0) FROM progression_snapshot WHERE user_id = :id',
            ['id' => $account->id->toRfc4122()],
        ));
    }

    private function ledgerSize(): int
    {
        return self::asInt($this->connection()->fetchOne('SELECT COUNT(*) FROM xp_transaction'));
    }

    private function countWorkouts(): int
    {
        return self::asInt($this->connection()->fetchOne('SELECT COUNT(*) FROM workout'));
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
