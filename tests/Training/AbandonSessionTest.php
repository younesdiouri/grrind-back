<?php

declare(strict_types=1);

namespace App\Tests\Training;

use App\Shared\UI\Http\IdempotencyListener;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Workouts;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class AbandonSessionTest extends ApiTestCase
{
    use Workouts;

    private const string KEY = '6a1c8f30-5d24-4b91-8e07-3f9b2d64c115';

    public function testClosesTheSessionWithoutCountingIt(): void
    {
        $bob = $this->openAccount();
        $session = $this->startSession($bob);

        $before = new DateTimeImmutable();
        $response = $this->abandon($bob, $session);
        $after = new DateTimeImmutable();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);
        self::assertSame($session, $body['id']);
        self::assertSame('ABANDONED', $body['status']);

        self::assertIsString($body['endedAt']);
        $endedAt = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $body['endedAt']);
        self::assertInstanceOf(DateTimeImmutable::class, $endedAt);
        self::assertGreaterThanOrEqual($before->getTimestamp(), $endedAt->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp(), $endedAt->getTimestamp());

        // La durée est renseignée même sans XP à la clé : c'est elle qui dira si
        // l'abandon enclenche le cooldown ou s'il est passé sous le plancher.
        self::assertIsInt($body['durationSeconds']);
        self::assertGreaterThanOrEqual(0, $body['durationSeconds']);
    }

    /**
     * Une séance abandonnée reste : l'historique du joueur n'est pas une liste de succès,
     * et le cooldown a besoin de savoir qu'elle a eu lieu.
     */
    public function testTheSessionIsKeptRatherThanErased(): void
    {
        $bob = $this->openAccount();
        $session = $this->startSession($bob);

        $this->abandon($bob, $session);

        self::assertSame('ABANDONED', $this->statusOf($session));
    }

    /**
     * Le rejeu du client mobile. Sans la clé, une requête perdue en route et renvoyée
     * rendrait un `409` — un échec affiché pour une action qui, elle, a réussi.
     */
    public function testReplayingTheSameKeyRendersTheFirstAnswer(): void
    {
        $bob = $this->openAccount();
        $session = $this->startSession($bob);

        $first = $this->abandon($bob, $session);
        $replayed = $this->abandon($bob, $session);

        self::assertSame(Response::HTTP_OK, $first->getStatusCode());
        self::assertSame(Response::HTTP_OK, $replayed->getStatusCode());
        self::assertSame($first->getContent(), $replayed->getContent());
        self::assertNull($first->headers->get(IdempotencyListener::REPLAY_HEADER));
        self::assertSame('true', $replayed->headers->get(IdempotencyListener::REPLAY_HEADER));
    }

    public function testDemandsTheIdempotencyKey(): void
    {
        $bob = $this->openAccount();
        $session = $this->startSession($bob);

        $response = $this->post(\sprintf('/api/training/sessions/%s/abandon', $session), [], $bob->headers);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertProblem($response, 'idempotency-key-required');
    }

    /**
     * Abandonner une séance déjà close, avec une clé neuve, n'est plus un rejeu : c'est
     * une seconde transition, et le domaine la refuse quel que soit le sens.
     */
    public function testRefusesToAbandonAClosedSession(): void
    {
        $bob = $this->openAccount();
        $session = $this->startSession($bob);
        $this->ageSession($session, 1800);

        self::assertSame(Response::HTTP_OK, $this->completeSession($bob, $session)->getStatusCode());
        $response = $this->abandon($bob, $session, key: 'une-autre-cle');

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertProblem($response, 'session-not-active');

        $body = self::decode($response);
        self::assertSame('COMPLETED', $body['sessionStatus']);
        self::assertSame($session, $body['sessionId']);
    }

    /**
     * L'inverse, qui compte tout autant : abandonner ne laisse pas la porte ouverte à
     * une clôture tardive qui, elle, rapporterait de l'XP au Lot 4. Le refus vient du
     * statut et non de la durée — l'ordre des contrôles compte.
     */
    public function testAnAbandonedSessionCannotBeCompletedAfterwards(): void
    {
        $bob = $this->openAccount();
        $session = $this->startSession($bob);

        $this->abandon($bob, $session);
        $response = $this->completeSession($bob, $session);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertProblem($response, 'session-not-active');
        self::assertSame('ABANDONED', self::decode($response)['sessionStatus']);
    }

    /**
     * La séance d'un autre compte est un 404, jamais un 403 : répondre « interdit »
     * confirmerait qu'elle existe.
     */
    public function testCannotAbandonSomeoneElsesSession(): void
    {
        $alice = $this->openAccount('alice@grrind.app', 'Alice');
        $bob = $this->openAccount();

        $hers = $this->startSession($alice);
        $response = $this->abandon($bob, $hers);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertProblem($response, 'session-not-found');
        self::assertSame('ACTIVE', $this->statusOf($hers));
    }

    public function testAnUnknownSessionIsNotFound(): void
    {
        $bob = $this->openAccount();

        $response = $this->abandon($bob, Uuid::v7()->toRfc4122());

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertProblem($response, 'session-not-found');
    }

    public function testRefusesAnAnonymousCall(): void
    {
        $response = $this->post(
            \sprintf('/api/training/sessions/%s/abandon', Uuid::v7()->toRfc4122()),
            headers: ['Idempotency-Key' => self::KEY],
        );

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
    }

    /**
     * Clé fixe, contrairement à {@see Workouts::abandonSession()} : c'est ici
     * le sujet du test, le rejeu comme le recyclage de clé doivent être observables.
     */
    private function abandon(Account $account, string $sessionId, string $key = self::KEY): Response
    {
        return $this->post(
            \sprintf('/api/training/sessions/%s/abandon', $sessionId),
            [],
            $account->headers + ['Idempotency-Key' => $key],
        );
    }

    private static function assertProblem(Response $response, string $type): void
    {
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame('https://grrind.app/problems/'.$type, self::decode($response)['type']);
    }
}
