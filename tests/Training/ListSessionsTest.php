<?php

declare(strict_types=1);

namespace App\Tests\Training;

use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Workouts;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'historique, seule route de `Training` à survivre au retrait du chronomètre (#85).
 * Elle devient `GET /api/workouts` au #93 ; ce qu'elle garantit — isolation, pagination,
 * filtres, refus lisibles — ne change pas d'ici là et vaut d'être tenu.
 *
 * Les workouts sont écrits en base : plus rien ne les crée par HTTP tant que l'import
 * n'existe pas (#88). Voir {@see Workouts}.
 */
final class ListSessionsTest extends ApiTestCase
{
    use Workouts;

    public function testAnEmptyHistoryIsAnEmptyListAndNotAnError(): void
    {
        $bob = $this->openAccount();

        $body = $this->history($bob);

        self::assertSame([], $body['sessions']);
        self::assertNull($body['nextCursor']);
    }

    public function testTheMostRecentComeFirst(): void
    {
        $bob = $this->openAccount();
        $first = $this->recordWorkout($bob);
        $second = $this->recordWorkout($bob);
        $third = $this->recordWorkout($bob);

        self::assertSame([$third, $second, $first], $this->idsOf($this->history($bob)));
    }

    /**
     * La pagination par curseur, et sa raison d'être : elle désigne une position dans
     * les données, pas un rang. Un workout importé entre deux pages ne décale donc rien
     * — là où un `OFFSET` aurait fait réapparaître la dernière ligne de la page lue.
     */
    public function testWalksThePagesWithoutRepeatingNorSkipping(): void
    {
        $bob = $this->openAccount();
        $all = array_reverse(array_map(fn (): string => $this->recordWorkout($bob), range(1, 5)));

        $firstPage = $this->history($bob, ['limit' => 2]);
        self::assertSame(\array_slice($all, 0, 2), $this->idsOf($firstPage));
        self::assertIsString($firstPage['nextCursor']);
        self::assertSame($all[1], $firstPage['nextCursor']);

        $this->recordWorkout($bob);

        $secondPage = $this->history($bob, ['limit' => 2, 'cursor' => $firstPage['nextCursor']]);
        self::assertSame(\array_slice($all, 2, 2), $this->idsOf($secondPage));
        self::assertIsString($secondPage['nextCursor']);

        $lastPage = $this->history($bob, ['limit' => 2, 'cursor' => $secondPage['nextCursor']]);
        self::assertSame([$all[4]], $this->idsOf($lastPage));

        // Plus rien après : le client s'arrête là, sans avoir jamais eu besoin d'un total.
        self::assertNull($lastPage['nextCursor']);
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
     * d'écriture. La distinction n'était que théorique tant que les deux coïncidaient ;
     * avec l'import elle est la règle, et le joueur qui cherche « mes séances de
     * juillet » ne veut pas les lignes écrites en août.
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
     * L'isolation ne tient pas à un contrôle qu'on pourrait oublier : le compte n'est
     * pas un paramètre de la requête, il vient du jeton. Il n'existe aucune requête
     * capable de demander l'historique d'un autre.
     */
    public function testAnAccountNeverSeesAnothersWorkouts(): void
    {
        $alice = $this->openAccount('alice@grrind.app', 'Alice');
        $bob = $this->openAccount();

        $hers = $this->recordWorkout($alice);
        $his = $this->recordWorkout($bob);

        self::assertSame([$his], $this->idsOf($this->history($bob)));
        self::assertSame([$hers], $this->idsOf($this->history($alice)));

        // Et son identifiant en curseur ne fait pas fuiter le sien pour autant.
        self::assertSame([], $this->history($bob, ['cursor' => $hers])['sessions']);
    }

    /**
     * Un workout est un fait passé : il n'a plus de statut, et le filtre qui existait pour
     * le lire a disparu avec lui.
     *
     * **Le paramètre est ignoré, pas refusé**, et ce test le dit tel quel plutôt que de
     * décrire ce qu'on préférerait : `#[MapQueryString]` laisse passer ce qu'il ne connaît
     * pas. Un client resté sur l'ancien contrat croit donc filtrer et reçoit tout. C'est
     * supportable ici — la route entière est remplacée au #93, et le front régénère son
     * client au grrind-app#19 — mais un `ALLOW_EXTRA_ATTRIBUTES => false` serait le vrai
     * garde-fou le jour où on le voudra.
     */
    public function testTheStatusFilterIsGoneAndAStaleClientNoLongerFilters(): void
    {
        $bob = $this->openAccount();
        $this->recordWorkout($bob);
        $this->recordWorkout($bob, 'CYCLING');

        self::assertCount(2, $this->history($bob, ['status' => 'COMPLETED'])['sessions']);
    }

    /**
     * Plus de `status`, plus d'`endedAt` ni de `durationSeconds` nuls : un workout
     * arrive terminé ou n'arrive pas.
     */
    public function testAWorkoutIsServedWithoutAStateAndWithBothItsBounds(): void
    {
        $bob = $this->openAccount();
        $this->recordWorkout($bob, durationSeconds: 2700);

        $workout = $this->history($bob)['sessions'][0];

        self::assertSame(
            ['id', 'discipline', 'source', 'trust', 'startedAt', 'endedAt', 'durationSeconds'],
            array_keys($workout),
        );
        self::assertSame(2700, $workout['durationSeconds']);
        self::assertIsString($workout['endedAt']);
    }

    public function testRefusesALimitBeyondTheCeiling(): void
    {
        $bob = $this->openAccount();

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->get('/api/training/sessions?limit=500', $bob->headers)->getStatusCode());
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->get('/api/training/sessions?limit=0', $bob->headers)->getStatusCode());
    }

    public function testRefusesAnUnreadableFilter(): void
    {
        $bob = $this->openAccount();

        foreach (['discipline=QUIDDITCH', 'cursor=pas-un-uuid', 'from=hier'] as $parameter) {
            $response = $this->get('/api/training/sessions?'.$parameter, $bob->headers);

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
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->get('/api/training/sessions')->getStatusCode());
    }

    /**
     * Les routes du chronomètre ne répondent plus. Un client resté sur l'ancien contrat
     * doit recevoir un 404 franc plutôt qu'un comportement à moitié vivant.
     */
    public function testTheTimerRoutesAreGone(): void
    {
        $bob = $this->openAccount();

        self::assertSame(Response::HTTP_NOT_FOUND, $this->get('/api/training/sessions/active', $bob->headers)->getStatusCode());

        // 405 et non 404 : le chemin existe toujours pour le GET de l'historique, c'est le
        // verbe qui n'est plus servi. Le client apprend la même chose.
        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $this->post('/api/training/sessions', ['discipline' => 'RUNNING'], $bob->headers)->getStatusCode());
    }

    /**
     * @param array<string, string|int> $parameters
     *
     * @return array{sessions: list<array<string, mixed>>, nextCursor: string|null}
     */
    private function history(Account $account, array $parameters = []): array
    {
        $response = $this->get('/api/training/sessions?'.http_build_query($parameters), $account->headers);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);
        self::assertIsArray($body['sessions']);
        self::assertTrue(null === $body['nextCursor'] || \is_string($body['nextCursor']));

        /** @var array{sessions: list<array<string, mixed>>, nextCursor: string|null} $body */
        return $body;
    }

    /**
     * @param array{sessions: list<array<string, mixed>>, nextCursor: string|null} $page
     *
     * @return list<string>
     */
    private function idsOf(array $page): array
    {
        return array_map(static function (array $session): string {
            self::assertIsString($session['id']);

            return $session['id'];
        }, $page['sessions']);
    }
}
