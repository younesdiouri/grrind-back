<?php

declare(strict_types=1);

namespace App\Tests\Training;

use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Workouts;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /api/workouts` — l'historique du joueur.
 *
 * Les workouts sont écrits en base plutôt qu'importés par HTTP : ce qui se vérifie ici est
 * une **lecture**, et la faire dépendre de tout l'arbitrage d'import ferait échouer ces
 * tests pour des raisons qui ne les concernent pas. Voir {@see Workouts}.
 */
final class ListWorkoutsTest extends ApiTestCase
{
    use Workouts;

    public function testAnEmptyHistoryIsAnEmptyListAndNotAnError(): void
    {
        $bob = $this->openAccount();

        $body = $this->history($bob);

        self::assertSame([], $body['workouts']);
        self::assertNull($body['nextCursor']);
    }

    /**
     * L'ordre est celui de la **pratique**, pas celui de l'écriture. La distinction n'était
     * que théorique tant que Grrind tenait le chronomètre ; avec l'import, dix workouts
     * vieux de dix jours sont écrits à la file et l'ancien tri par UUID v7 aurait rendu
     * l'ordre de la synchronisation.
     */
    public function testTheMostRecentlyPractisedComeFirstWhateverTheWritingOrder(): void
    {
        $bob = $this->openAccount();

        // Écrits dans le désordre, exprès : c'est `startedAt` qui décide.
        $mercredi = $this->recordWorkout($bob, endedAt: new DateTimeImmutable('2026-07-15T08:00:00+00:00'));
        $lundi = $this->recordWorkout($bob, endedAt: new DateTimeImmutable('2026-07-13T08:00:00+00:00'));
        $mardi = $this->recordWorkout($bob, endedAt: new DateTimeImmutable('2026-07-14T08:00:00+00:00'));

        self::assertSame([$mercredi, $mardi, $lundi], $this->idsOf($this->history($bob)));
    }

    /**
     * La pagination par curseur, et sa raison d'être : elle désigne une position dans les
     * données, pas un rang. Un workout importé entre deux pages ne décale donc rien — là où
     * un `OFFSET` aurait fait réapparaître la dernière ligne de la page lue.
     */
    public function testWalksThePagesWithoutRepeatingNorSkipping(): void
    {
        $bob = $this->openAccount();

        $all = [];
        for ($day = 5; $day >= 1; --$day) {
            $all[] = $this->recordWorkout($bob, endedAt: new DateTimeImmutable(\sprintf('2026-07-0%dT08:00:00+00:00', $day)));
        }

        $firstPage = $this->history($bob, ['limit' => 2]);
        self::assertSame(\array_slice($all, 0, 2), $this->idsOf($firstPage));
        self::assertIsString($firstPage['nextCursor']);

        // Un workout intercalé pendant le défilement ne décale rien : il se range à sa date
        // et n'a aucun effet sur les pages déjà servies.
        $intercale = $this->recordWorkout($bob, endedAt: new DateTimeImmutable('2026-06-01T08:00:00+00:00'));

        $secondPage = $this->history($bob, ['limit' => 2, 'cursor' => $firstPage['nextCursor']]);
        self::assertSame(\array_slice($all, 2, 2), $this->idsOf($secondPage));
        self::assertIsString($secondPage['nextCursor']);

        $lastPage = $this->history($bob, ['limit' => 2, 'cursor' => $secondPage['nextCursor']]);
        self::assertSame([$all[4], $intercale], $this->idsOf($lastPage));

        // Plus rien après : le client s'arrête là, sans avoir jamais eu besoin d'un total.
        self::assertNull($lastPage['nextCursor']);
    }

    /**
     * **Le cas que le curseur composite existe pour couvrir.** Deux workouts commencés à la
     * même seconde — deux appareils, ou une reprise d'archive — ne doivent être ni rendus
     * deux fois ni sautés. Un curseur qui ne porterait que la date s'arrêterait entre les
     * deux sans savoir lequel il a déjà servi.
     */
    public function testTwoWorkoutsStartedAtTheSameSecondAreNeitherRepeatedNorSkipped(): void
    {
        $bob = $this->openAccount();
        $sameSecond = new DateTimeImmutable('2026-07-15T08:30:00+00:00');

        $this->recordWorkout($bob, endedAt: $sameSecond, externalId: 'HK-A');
        $this->recordWorkout($bob, endedAt: $sameSecond, externalId: 'HK-B');
        $this->recordWorkout($bob, endedAt: $sameSecond, externalId: 'HK-C');

        $seen = [];
        $cursor = null;

        do {
            $page = $this->history($bob, null === $cursor ? ['limit' => 1] : ['limit' => 1, 'cursor' => $cursor]);
            $seen = [...$seen, ...$this->idsOf($page)];
            $cursor = $page['nextCursor'];
        } while (null !== $cursor);

        self::assertCount(3, $seen);
        self::assertSame($seen, array_unique($seen), 'Aucun workout ne doit être rendu deux fois.');
    }

    public function testFiltersOnDiscipline(): void
    {
        $bob = $this->openAccount();
        $run = $this->recordWorkout($bob);
        $ride = $this->recordWorkout($bob, 'CYCLING');

        self::assertSame([$run], $this->idsOf($this->history($bob, ['discipline' => 'RUNNING'])));
        self::assertSame([$ride], $this->idsOf($this->history($bob, ['discipline' => 'CYCLING'])));
    }

    /**
     * La fenêtre porte sur `startedAt`, le fait sportif — pas sur `createdAt`, la date
     * d'écriture. Le joueur qui cherche « mes séances de juillet » ne veut pas les lignes
     * écrites en août.
     */
    public function testFiltersOnADateWindow(): void
    {
        $bob = $this->openAccount();
        $july = $this->recordWorkout($bob, endedAt: new DateTimeImmutable('2026-07-15T11:00:00+02:00'));
        $today = $this->recordWorkout($bob);

        $window = ['from' => '2026-07-01T00:00:00+02:00', 'to' => '2026-08-01T00:00:00+02:00'];
        self::assertSame([$july], $this->idsOf($this->history($bob, $window)));

        self::assertSame([$today], $this->idsOf($this->history($bob, ['from' => '2026-08-01T00:00:00+02:00'])));
    }

    /**
     * L'isolation ne tient pas à un contrôle qu'on pourrait oublier : le compte n'est pas un
     * paramètre de la requête, il vient du jeton. Il n'existe aucune requête capable de
     * demander l'historique d'un autre.
     */
    public function testAnAccountNeverSeesAnothersWorkouts(): void
    {
        $alice = $this->openAccount('alice@grrind.app', 'Alice');
        $bob = $this->openAccount();

        $hers = $this->recordWorkout($alice);
        $his = $this->recordWorkout($bob);

        self::assertSame([$his], $this->idsOf($this->history($bob)));
        self::assertSame([$hers], $this->idsOf($this->history($alice)));
    }

    /**
     * Les mesures de la montre traversent jusqu'au contrat, et **l'absence se distingue du
     * zéro** : `null` veut dire « non mesuré ». Un client qui les confondrait afficherait
     * « 0 km » à un joueur qui vient de soulever de la fonte.
     */
    public function testAWorkoutIsServedWithItsMeasurementsAndTheirAbsence(): void
    {
        $bob = $this->openAccount();
        $this->recordWorkout($bob, durationSeconds: 2700, distanceMeters: 8400, averageHeartRate: 149, externalId: 'HK-001');

        $workout = $this->history($bob)['workouts'][0];

        self::assertSame(
            ['id', 'discipline', 'source', 'trust', 'startedAt', 'endedAt', 'durationSeconds', 'distanceMeters', 'calories', 'elevationGainMeters', 'averageHeartRate', 'externalId'],
            array_keys($workout),
        );
        self::assertSame(2700, $workout['durationSeconds']);
        self::assertSame(8400, $workout['distanceMeters']);
        self::assertSame(149, $workout['averageHeartRate']);
        self::assertNull($workout['calories']);
        self::assertNull($workout['elevationGainMeters']);
        self::assertSame('HK-001', $workout['externalId']);
    }

    public function testRefusesALimitBeyondTheCeiling(): void
    {
        $bob = $this->openAccount();

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->get('/api/workouts?limit=500', $bob->headers)->getStatusCode());
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->get('/api/workouts?limit=0', $bob->headers)->getStatusCode());
    }

    /**
     * Un curseur bricolé à la main est un 422 qui le nomme, et surtout **pas** une page
     * vide : celle-ci ferait croire au client qu'il est arrivé au bout de l'historique.
     */
    public function testRefusesAnUnreadableFilter(): void
    {
        $bob = $this->openAccount();

        foreach (['discipline=QUIDDITCH', 'cursor=pas-un-curseur', 'from=hier'] as $parameter) {
            $response = $this->get('/api/workouts?'.$parameter, $bob->headers);

            self::assertSame(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $response->getStatusCode(),
                'Attendu 422 pour « '.$parameter.' », reçu '.(string) $response->getContent(),
            );
            self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        }
    }

    public function testRefusesAnonymousCalls(): void
    {
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->get('/api/workouts')->getStatusCode());
    }

    /**
     * Les routes du chronomètre ne répondent plus, et l'ancien chemin non plus. Un client
     * resté sur l'ancien contrat reçoit un 404 franc plutôt qu'un comportement à moitié
     * vivant.
     */
    public function testTheTimerRoutesAndTheOldPathAreGone(): void
    {
        $bob = $this->openAccount();

        self::assertSame(Response::HTTP_NOT_FOUND, $this->get('/api/training/sessions', $bob->headers)->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $this->get('/api/training/sessions/active', $bob->headers)->getStatusCode());

        // 405 et non 404 : le chemin existe toujours pour le GET de l'historique, c'est le
        // verbe qui n'est plus servi. Le client apprend la même chose.
        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $this->post('/api/workouts', ['discipline' => 'RUNNING'], $bob->headers)->getStatusCode());
    }

    /**
     * @param array<string, string|int> $parameters
     *
     * @return array{workouts: list<array<string, mixed>>, nextCursor: string|null}
     */
    private function history(Account $account, array $parameters = []): array
    {
        $response = $this->get('/api/workouts?'.http_build_query($parameters), $account->headers);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);
        self::assertIsArray($body['workouts']);
        self::assertTrue(null === $body['nextCursor'] || \is_string($body['nextCursor']));

        /** @var array{workouts: list<array<string, mixed>>, nextCursor: string|null} $body */
        return $body;
    }

    /**
     * @param array{workouts: list<array<string, mixed>>, nextCursor: string|null} $page
     *
     * @return list<string>
     */
    private function idsOf(array $page): array
    {
        return array_map(static function (array $workout): string {
            self::assertIsString($workout['id']);

            return $workout['id'];
        }, $page['workouts']);
    }
}
