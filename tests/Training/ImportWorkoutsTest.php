<?php

declare(strict_types=1);

namespace App\Tests\Training;

use App\Shared\UI\Http\IdempotencyListener;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Workouts;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /api/workouts/import` — la nouvelle porte d'entrée du produit.
 *
 * Ce qui se joue ici tient en une phrase : **un import est un ensemble, pas une
 * transaction**. La plupart de ces tests envoient donc un lot mélangé et vérifient que le
 * bon grain passe pendant que l'ivraie est nommée.
 */
final class ImportWorkoutsTest extends ApiTestCase
{
    use Workouts;

    public function testImportsAWorkoutTheWatchRecorded(): void
    {
        $bob = $this->openAccount();

        $response = $this->import($bob, [self::candidate()]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);
        self::assertIsArray($body['imported']);
        self::assertCount(1, $body['imported']);
        self::assertSame([], $body['skipped']);

        $workout = $body['imported'][0];
        self::assertIsArray($workout);
        self::assertSame('RUNNING', $workout['discipline']);
        self::assertSame('APPLE_HEALTH', $workout['source']);
        self::assertSame('PROVIDER_VERIFIED', $workout['trust']);
    }

    /**
     * Le client envoie deux bornes, jamais une durée. Elle se recalcule ici, et c'est ce
     * qui empêche `duration: 36000` sur un quart d'heure de course.
     */
    public function testTheDurationIsDerivedFromTheProviderBounds(): void
    {
        $bob = $this->openAccount();

        $response = $this->import($bob, [self::candidate(
            startedAt: '2026-08-11T07:00:00+00:00',
            endedAt: '2026-08-11T07:45:00+00:00',
        )]);

        $body = self::decode($response);
        self::assertIsArray($body['imported']);
        $workout = $body['imported'][0];
        self::assertIsArray($workout);
        self::assertSame(2700, $workout['durationSeconds']);
    }

    /**
     * Les mesures traversent jusqu'à la base. Celles qui manquent restent nulles : « non
     * mesuré » n'est pas zéro, et le calcul d'XP (#90) devra lire la différence.
     */
    public function testTheMeasurementsAreStoredAndTheMissingOnesStayNull(): void
    {
        $bob = $this->openAccount();

        $this->import($bob, [self::candidate(
            distanceMeters: 6200,
            calories: 487,
            averageHeartRate: 152,
        )]);

        $row = $this->connection()->fetchAssociative(
            'SELECT distance_meters, calories, elevation_gain_meters, average_heart_rate, external_id FROM workout',
        );

        self::assertSame(
            [
                'distance_meters' => 6200,
                'calories' => 487,
                'elevation_gain_meters' => null,
                'average_heart_rate' => 152,
                'external_id' => 'HK-001',
            ],
            $row,
        );
    }

    /**
     * Le cas nominal d'un client qui a perdu son curseur : il renvoie tout, et rien ne
     * doit doubler. Le refus est **silencieux** — un doublon n'est pas une erreur.
     */
    public function testAWorkoutAlreadyImportedIsSkippedAndNotCredited(): void
    {
        $bob = $this->openAccount();
        $this->import($bob, [self::candidate()]);

        $response = $this->import($bob, [self::candidate()], key: 'seconde-tentative');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $body = self::decode($response);
        self::assertSame([], $body['imported']);
        self::assertSame(
            [['externalId' => 'HK-001', 'activityType' => 'running', 'reason' => 'ALREADY_IMPORTED']],
            $body['skipped'],
        );
        self::assertSame(1, $this->countWorkouts());
    }

    /**
     * Le lot est une source de doublons au même titre que la base : un client qui
     * concatène deux pages de HealthKit peut envoyer deux fois la même séance. Sans
     * dédoublonnage interne, c'est la contrainte d'unicité qui le découvrirait — en
     * faisant échouer tout le reste du lot.
     */
    public function testTheSameWorkoutTwiceInOneBatchIsImportedOnce(): void
    {
        $bob = $this->openAccount();

        $response = $this->import($bob, [self::candidate(), self::candidate()]);

        $body = self::decode($response);
        self::assertIsArray($body['imported']);
        self::assertIsArray($body['skipped']);
        self::assertCount(1, $body['imported']);
        self::assertCount(1, $body['skipped']);
        self::assertSame(1, $this->countWorkouts());
    }

    /**
     * La même séance vue par les deux fournisseurs n'est **pas** un doublon : l'unicité est
     * par source. Ce chevauchement-là se tranche au #91, avec ce qu'il faut de contexte.
     */
    public function testTheSameIdentifierUnderAnotherSourceIsImported(): void
    {
        $bob = $this->openAccount();

        $response = $this->import($bob, [
            self::candidate(),
            self::candidate(source: 'HEALTH_CONNECT', activityType: 'EXERCISE_TYPE_RUNNING'),
        ]);

        $body = self::decode($response);
        self::assertIsArray($body['imported']);
        self::assertCount(2, $body['imported']);
        self::assertSame(2, $this->countWorkouts());
    }

    /**
     * Une activité que Grrind ne traduit pas ne casse pas le lot — et elle est **nommée**,
     * pas comptée : le client doit pouvoir écrire « le curling n'est pas encore un sport
     * chez nous » plutôt que « 1 séance ignorée ».
     */
    public function testAnUnsupportedActivityIsNamedAndTheRestOfTheBatchGoesThrough(): void
    {
        $bob = $this->openAccount();

        $response = $this->import($bob, [
            self::candidate(externalId: 'HK-CURLING', activityType: 'curling'),
            self::candidate(),
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $body = self::decode($response);
        self::assertIsArray($body['imported']);
        self::assertCount(1, $body['imported']);
        self::assertSame(
            [['externalId' => 'HK-CURLING', 'activityType' => 'curling', 'reason' => 'UNSUPPORTED_ACTIVITY']],
            $body['skipped'],
        );
    }

    /**
     * Rien n'a été écrit pour une activité non traduite, donc rien n'est définitif : le
     * jour où le sport entre dans `activity_types.yaml`, la même séance renvoyée est
     * créditée. C'est le bénéfice direct d'une table serveur, et il se teste en changeant
     * le seul type entre deux appels.
     */
    public function testAnActivitySkippedAsUnsupportedIsNotRememberedAsRefused(): void
    {
        $bob = $this->openAccount();
        $this->import($bob, [self::candidate(activityType: 'curling')]);

        $response = $this->import($bob, [self::candidate()], key: 'apres-ouverture-du-sport');

        $body = self::decode($response);
        self::assertIsArray($body['imported']);
        self::assertCount(1, $body['imported']);
    }

    /**
     * Un import qui ne crédite rien reste un **succès**. C'est ce que rend un client qui
     * resynchronise sans rien de neuf, et le distinguer d'un échec obligerait le client à
     * deviner.
     */
    public function testABatchWhereEverythingIsSkippedIsStillASuccess(): void
    {
        $bob = $this->openAccount();

        $response = $this->import($bob, [self::candidate(activityType: 'curling')]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame([], self::decode($response)['imported']);
    }

    /**
     * Un joueur n'importe que pour lui : l'identifiant vient du jeton, jamais du corps.
     */
    public function testAnImportOnlyEverWritesForTheAuthenticatedPlayer(): void
    {
        $bob = $this->openAccount();
        $alice = $this->openAccount('alice@grrind.app', 'Alice');

        $this->import($bob, [self::candidate()]);
        $this->import($alice, [self::candidate()]);

        self::assertSame(1, $this->countWorkouts('user_id = :id', ['id' => $bob->id->toRfc4122()]));
        self::assertSame(1, $this->countWorkouts('user_id = :id', ['id' => $alice->id->toRfc4122()]));
    }

    /**
     * Le rejeu d'une requête dont la réponse s'est perdue rend la **réponse d'origine**, pas
     * un import vide. C'est exactement ce que l'unicité par `externalId` ne sait pas faire :
     * sans l'idempotence, le client recevrait `imported: []` et perdrait sa mise en scène.
     */
    public function testReplayingTheSameKeyGivesBackTheOriginalResponse(): void
    {
        $bob = $this->openAccount();

        $first = $this->import($bob, [self::candidate()]);
        $replay = $this->import($bob, [self::candidate()]);

        self::assertSame('true', $replay->headers->get(IdempotencyListener::REPLAY_HEADER));
        self::assertSame($first->getContent(), $replay->getContent());
        self::assertSame(1, $this->countWorkouts());
    }

    public function testAnImportWithoutAnIdempotencyKeyIsRefused(): void
    {
        $bob = $this->openAccount();

        $response = $this->post('/api/workouts/import', ['workouts' => [self::candidate()]], $bob->headers);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testAnImportWithoutATokenIsRefused(): void
    {
        $response = $this->post('/api/workouts/import', ['workouts' => [self::candidate()]], ['Idempotency-Key' => 'sans-jeton']);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    /**
     * Une source que le contrat ne connaît pas est un **bug du client**, pas une séance à
     * écarter — d'où le 422, là où un `activityType` inconnu passe en silence. L'asymétrie
     * est le point : la traduction est un réglage de jeu, l'énumération des sources est le
     * contrat.
     */
    public function testAnUnknownSourceIsRefusedWhereAnUnknownActivityIsNot(): void
    {
        $bob = $this->openAccount();

        $response = $this->import($bob, [self::candidate(source: 'STRAVA')]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    /**
     * La validation descend dans chaque élément du lot — sans `#[Assert\Valid]`, seul le
     * nombre serait vérifié et un `externalId` vide entrerait en base.
     */
    public function testAWorkoutWithoutAProviderIdentifierIsRefused(): void
    {
        $bob = $this->openAccount();

        $response = $this->import($bob, [self::candidate(externalId: '')]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame(0, $this->countWorkouts());
    }

    public function testAnEmptyBatchIsRefused(): void
    {
        $bob = $this->openAccount();

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->import($bob, [])->getStatusCode());
    }

    /**
     * Le plafond n'ampute personne : la fenêtre d'antériorité (#91) dit jusqu'où on
     * remonte, et un premier import se pagine côté client. Il est là pour qu'une requête
     * ne devienne pas une reprise de trois ans d'historique.
     */
    public function testABatchOverTheCeilingIsRefused(): void
    {
        $bob = $this->openAccount();

        $lot = [];
        for ($i = 0; $i <= 200; ++$i) {
            $lot[] = self::candidate(externalId: 'HK-'.$i);
        }

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->import($bob, $lot)->getStatusCode());
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
        string $source = 'APPLE_HEALTH',
        string $activityType = 'running',
        string $startedAt = '2026-08-11T07:02:13+00:00',
        string $endedAt = '2026-08-11T07:47:55+00:00',
        ?int $distanceMeters = null,
        ?int $calories = null,
        ?int $elevationGainMeters = null,
        ?int $averageHeartRate = null,
    ): array {
        return [
            'externalId' => $externalId,
            'source' => $source,
            'activityType' => $activityType,
            'startedAt' => $startedAt,
            'endedAt' => $endedAt,
            'distanceMeters' => $distanceMeters,
            'calories' => $calories,
            'elevationGainMeters' => $elevationGainMeters,
            'averageHeartRate' => $averageHeartRate,
        ];
    }

    /**
     * @param array<string, string> $parameters
     */
    private function countWorkouts(string $where = 'TRUE', array $parameters = []): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM workout WHERE '.$where, $parameters);
        \assert(is_numeric($count));

        return (int) $count;
    }
}
