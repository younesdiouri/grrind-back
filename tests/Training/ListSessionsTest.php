<?php

declare(strict_types=1);

namespace App\Tests\Training;

use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Workouts;
use Symfony\Component\HttpFoundation\Response;

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
        $first = $this->pastSession($bob);
        $second = $this->pastSession($bob);
        $third = $this->startSession($bob);

        self::assertSame([$third, $second, $first], $this->idsOf($this->history($bob)));
    }

    /**
     * La pagination par curseur, et sa raison d'être : elle désigne une position dans
     * les données, pas un rang. Une séance ouverte entre deux pages ne décale donc rien
     * — là où un `OFFSET` aurait fait réapparaître la dernière ligne de la page lue.
     */
    public function testWalksThePagesWithoutRepeatingNorSkipping(): void
    {
        $bob = $this->openAccount();
        $all = array_reverse(array_map(fn (): string => $this->pastSession($bob), range(1, 5)));

        $firstPage = $this->history($bob, ['limit' => 2]);
        self::assertSame(\array_slice($all, 0, 2), $this->idsOf($firstPage));
        self::assertIsString($firstPage['nextCursor']);
        self::assertSame($all[1], $firstPage['nextCursor']);

        $this->startSession($bob);

        $secondPage = $this->history($bob, ['limit' => 2, 'cursor' => $firstPage['nextCursor']]);
        self::assertSame(\array_slice($all, 2, 2), $this->idsOf($secondPage));
        self::assertIsString($secondPage['nextCursor']);

        $lastPage = $this->history($bob, ['limit' => 2, 'cursor' => $secondPage['nextCursor']]);
        self::assertSame([$all[4]], $this->idsOf($lastPage));

        // Plus rien après : le client s'arrête là, sans avoir jamais eu besoin d'un total.
        self::assertNull($lastPage['nextCursor']);
    }

    public function testFiltersOnStatus(): void
    {
        // Dans cet ordre, et pas un autre : une seule séance tourne à la fois, donc
        // celle qui reste ACTIVE est forcément la dernière ouverte.
        $bob = $this->openAccount();
        $done = $this->pastSession($bob);
        $dropped = $this->startSession($bob);
        $this->abandonSession($bob, $dropped);
        $running = $this->startSession($bob);

        self::assertSame([$done], $this->idsOf($this->history($bob, ['status' => 'COMPLETED'])));
        self::assertSame([$dropped], $this->idsOf($this->history($bob, ['status' => 'ABANDONED'])));
        self::assertSame([$running], $this->idsOf($this->history($bob, ['status' => 'ACTIVE'])));
    }

    public function testFiltersOnDiscipline(): void
    {
        $bob = $this->openAccount();
        $run = $this->pastSession($bob);
        $ride = $this->startSession($bob, 'CYCLING');

        self::assertSame([$run], $this->idsOf($this->history($bob, ['discipline' => 'RUNNING'])));
        self::assertSame([$ride], $this->idsOf($this->history($bob, ['discipline' => 'CYCLING'])));
    }

    /**
     * La fenêtre porte sur `startedAt`, le fait sportif. Aucune route ne permet
     * d'antidater — c'est l'invariant du projet — donc le test pose la séance ancienne
     * en base directement : ce qu'on vérifie ici est le filtre, pas l'horloge.
     */
    public function testFiltersOnADateWindow(): void
    {
        $bob = $this->openAccount();
        $july = $this->pastSession($bob);
        $today = $this->startSession($bob);

        $this->backdate($july, '2026-07-15T10:00:00+02:00');

        $window = ['from' => '2026-07-01T00:00:00+02:00', 'to' => '2026-08-01T00:00:00+02:00'];
        self::assertSame([$july], $this->idsOf($this->history($bob, $window)));

        self::assertSame([$today], $this->idsOf($this->history($bob, ['from' => '2026-08-01T00:00:00+02:00'])));
    }

    /**
     * L'isolation ne tient pas à un contrôle qu'on pourrait oublier : le compte n'est
     * pas un paramètre de la requête, il vient du jeton. Il n'existe aucune requête
     * capable de demander l'historique d'un autre.
     */
    public function testAnAccountNeverSeesAnothersSessions(): void
    {
        $alice = $this->openAccount('alice@grrind.app', 'Alice');
        $bob = $this->openAccount();

        $hers = $this->startSession($alice);
        $his = $this->startSession($bob);

        self::assertSame([$his], $this->idsOf($this->history($bob)));
        self::assertSame([$hers], $this->idsOf($this->history($alice)));

        // Et son identifiant en curseur ne fait pas fuiter la sienne pour autant.
        self::assertSame([], $this->history($bob, ['cursor' => $hers])['sessions']);
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

        foreach (['status=PARTIE', 'discipline=QUIDDITCH', 'cursor=pas-un-uuid', 'from=hier'] as $parameter) {
            $response = $this->get('/api/training/sessions?'.$parameter, $bob->headers);

            self::assertSame(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $response->getStatusCode(),
                'Attendu 422 pour « '.$parameter.' », reçu '.(string) $response->getContent(),
            );
            self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        }
    }

    public function testTheRunningSessionIsFoundInOneRequest(): void
    {
        $bob = $this->openAccount();
        $session = $this->startSession($bob);

        $response = $this->get('/api/training/sessions/active', $bob->headers);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        $body = self::decode($response);
        self::assertSame($session, $body['id']);
        self::assertSame('ACTIVE', $body['status']);
    }

    /**
     * N'avoir aucune séance en cours est l'état normal du joueur, pas un échec : 204,
     * pour que le client ne traite pas un cas nominal dans sa branche d'erreur.
     */
    public function testNoRunningSessionIsAnEmptyAnswerAndNotAnError(): void
    {
        $bob = $this->openAccount();

        $empty = $this->get('/api/training/sessions/active', $bob->headers);
        self::assertSame(Response::HTTP_NO_CONTENT, $empty->getStatusCode());
        self::assertSame('', $empty->getContent());

        $session = $this->startSession($bob);
        $this->ageSession($session, 1800);
        $this->completeSession($bob, $session);

        // Close, elle ne « tourne » plus : le chronomètre du client ne doit pas repartir.
        self::assertSame(Response::HTTP_NO_CONTENT, $this->get('/api/training/sessions/active', $bob->headers)->getStatusCode());
    }

    public function testTheRunningSessionOfAnotherAccountIsInvisible(): void
    {
        $alice = $this->openAccount('alice@grrind.app', 'Alice');
        $bob = $this->openAccount();

        $this->startSession($alice);

        self::assertSame(Response::HTTP_NO_CONTENT, $this->get('/api/training/sessions/active', $bob->headers)->getStatusCode());
    }

    public function testRefusesAnonymousCalls(): void
    {
        foreach (['/api/training/sessions', '/api/training/sessions/active'] as $uri) {
            self::assertSame(Response::HTTP_UNAUTHORIZED, $this->get($uri)->getStatusCode(), $uri);
        }
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

    /**
     * Une date de début absolue, là où {@see Workouts::ageSession()} déplace
     * relativement : la fenêtre se teste sur un mois nommé, pas sur « il y a longtemps ».
     */
    private function backdate(string $sessionId, string $startedAt): void
    {
        $this->connection()->executeStatement(
            'UPDATE workout SET started_at = :startedAt WHERE id = :id',
            ['startedAt' => $startedAt, 'id' => $sessionId],
        );
    }
}
