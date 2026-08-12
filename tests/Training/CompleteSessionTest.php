<?php

declare(strict_types=1);

namespace App\Tests\Training;

use App\Shared\UI\Http\IdempotencyListener;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\TrainingSessions;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class CompleteSessionTest extends ApiTestCase
{
    use TrainingSessions;

    private const string KEY = 'd0f6b2c4-7a11-4e3c-9b8f-5c2a1e7d4406';

    /**
     * Une demi-heure de séance, comptée par le serveur seul.
     */
    private const int ELAPSED = 1800;

    public function testClosesTheSessionOnTheServerClock(): void
    {
        $bob = $this->openAccount();
        $session = $this->runningSession($bob);

        $before = new DateTimeImmutable();
        $response = $this->complete($bob, $session);
        $after = new DateTimeImmutable();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        // La séance close vit sous `session` : la réponse décrit désormais une
        // *récompense*, dont la séance n'est que le premier temps. Voir #22.
        $body = self::decode($response)['session'];
        self::assertIsArray($body);

        self::assertSame($session, $body['id']);
        self::assertSame('COMPLETED', $body['status']);

        self::assertIsString($body['endedAt']);
        $endedAt = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $body['endedAt']);
        self::assertInstanceOf(DateTimeImmutable::class, $endedAt);
        self::assertGreaterThanOrEqual($before->getTimestamp(), $endedAt->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp(), $endedAt->getTimestamp());

        // La durée est celle que le serveur a mesurée entre ses deux dates à lui.
        self::assertIsString($body['startedAt']);
        $startedAt = new DateTimeImmutable($body['startedAt']);
        self::assertSame($endedAt->getTimestamp() - $startedAt->getTimestamp(), $body['durationSeconds']);
        self::assertEqualsWithDelta(self::ELAPSED, $body['durationSeconds'], 2);
    }

    /**
     * Le cœur du ticket. Une durée envoyée par le client — ou une date de fin — ne
     * change rien : le serveur ne lit pas le corps de la requête, il lit son horloge.
     */
    public function testIgnoresADurationSentByTheClient(): void
    {
        $bob = $this->openAccount();
        $session = $this->runningSession($bob);

        $response = $this->complete($bob, $session, [
            'durationSeconds' => 86400,
            'endedAt' => '2030-01-01T00:00:00+00:00',
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response)['session'];
        self::assertIsArray($body);
        self::assertEqualsWithDelta(self::ELAPSED, $body['durationSeconds'], 2);
        self::assertIsString($body['endedAt']);
        self::assertStringStartsNotWith('2030', $body['endedAt']);
    }

    /**
     * Le rejeu du client mobile : même clé, même requête, une seule clôture. Sans ça,
     * la transaction du Lot 4 accorderait l'XP deux fois.
     */
    public function testReplayingTheSameKeyDoesNotCloseTheSessionTwice(): void
    {
        $bob = $this->openAccount();
        $session = $this->runningSession($bob);

        $first = $this->complete($bob, $session);
        $replayed = $this->complete($bob, $session);

        self::assertSame(Response::HTTP_OK, $first->getStatusCode());
        self::assertSame(Response::HTTP_OK, $replayed->getStatusCode());
        self::assertSame($first->getContent(), $replayed->getContent());
        self::assertSame('true', $replayed->headers->get(IdempotencyListener::REPLAY_HEADER));

        // La preuve que la règle n'a pas rejoué : la clôture serait passée par
        // `SessionNotActive` et aurait rendu un 409, pas la réponse d'origine.
        self::assertNull($first->headers->get(IdempotencyListener::REPLAY_HEADER));
    }

    public function testDemandsTheIdempotencyKey(): void
    {
        $bob = $this->openAccount();
        $session = $this->runningSession($bob);

        $response = $this->post(\sprintf('/api/training/sessions/%s/complete', $session), [], $bob->headers);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertProblem($response, 'idempotency-key-required');
    }

    /**
     * Une clé neuve sur une séance déjà close n'est plus un rejeu : c'est une seconde
     * clôture, et le domaine la refuse. Le client apprend le statut réel et se recale.
     */
    public function testRefusesToCompleteAnAlreadyClosedSession(): void
    {
        $bob = $this->openAccount();
        $session = $this->runningSession($bob);

        $this->complete($bob, $session);
        $response = $this->complete($bob, $session, key: 'une-autre-cle');

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertProblem($response, 'session-not-active');

        // Le statut de la séance doit survivre au problem details, dont le membre
        // `status` est déjà pris par le code HTTP — sans quoi le client n'apprend rien.
        $body = self::decode($response);
        self::assertSame(Response::HTTP_CONFLICT, $body['status']);
        self::assertSame('COMPLETED', $body['sessionStatus']);
        self::assertSame($session, $body['sessionId']);
    }

    /**
     * La séance d'un autre compte est un 404, jamais un 403 : répondre « interdit »
     * confirmerait qu'elle existe, et un identifiant, ça s'essaie en boucle.
     */
    public function testCannotCloseSomeoneElsesSession(): void
    {
        $alice = $this->openAccount('alice@grrind.app', 'Alice');
        $bob = $this->openAccount();

        $hers = $this->runningSession($alice);
        $response = $this->complete($bob, $hers);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertProblem($response, 'session-not-found');

        // Et la séance d'Alice n'a pas bougé.
        self::assertSame('ACTIVE', $this->statusOf($hers));
    }

    public function testAnUnknownSessionIsNotFound(): void
    {
        $bob = $this->openAccount();

        $response = $this->complete($bob, Uuid::v7()->toRfc4122());

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertProblem($response, 'session-not-found');
    }

    public function testRefusesAnAnonymousCall(): void
    {
        $response = $this->post(
            \sprintf('/api/training/sessions/%s/complete', Uuid::v7()->toRfc4122()),
            headers: ['Idempotency-Key' => self::KEY],
        );

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
    }

    /**
     * Une séance en cours depuis une demi-heure : au-dessus de la durée plancher, donc
     * clôturable. Aucune route ne permet d'antidater — c'est le stockage qu'on recule,
     * pas le comportement qu'on contourne. Voir {@see TrainingSessions}.
     */
    private function runningSession(Account $account): string
    {
        $id = $this->startSession($account);
        $this->ageSession($id, self::ELAPSED);

        return $id;
    }

    /**
     * @param array<string, mixed> $payload de quoi vérifier que le serveur n'en lit rien
     */
    private function complete(Account $account, string $sessionId, array $payload = [], string $key = self::KEY): Response
    {
        return $this->post(
            \sprintf('/api/training/sessions/%s/complete', $sessionId),
            $payload,
            $account->headers + ['Idempotency-Key' => $key],
        );
    }

    private static function assertProblem(Response $response, string $type): void
    {
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame('https://grrind.app/problems/'.$type, self::decode($response)['type']);
    }
}
