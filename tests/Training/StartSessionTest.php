<?php

declare(strict_types=1);

namespace App\Tests\Training;

use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;

final class StartSessionTest extends ApiTestCase
{
    public function testOpensASessionOnTheServerClock(): void
    {
        $account = $this->openAccount();

        $before = new DateTimeImmutable();
        $response = $this->post('/api/training/sessions', ['discipline' => 'RUNNING'], $account->headers);
        $after = new DateTimeImmutable();

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);

        self::assertIsString($body['id']);
        self::assertSame('RUNNING', $body['discipline']);
        self::assertSame('ACTIVE', $body['status']);
        self::assertSame('MANUAL_TIMER', $body['source']);
        self::assertSame('DECLARED', $body['trust']);

        self::assertIsString($body['startedAt']);
        $startedAt = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $body['startedAt']);
        self::assertInstanceOf(DateTimeImmutable::class, $startedAt);
        self::assertGreaterThanOrEqual($before->getTimestamp(), $startedAt->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp(), $startedAt->getTimestamp());
    }

    /**
     * Le cœur du ticket : le serveur possède l'horloge. Une date envoyée par le client
     * n'est pas refusée, elle est simplement ignorée — c'est le comportement du
     * Serializer sur un champ inconnu, et il vaut mieux qu'un 422 : un vieux client
     * qui enverrait le champ continue de fonctionner sans pouvoir antidater.
     */
    public function testIgnoresAStartedAtSentByTheClient(): void
    {
        $account = $this->openAccount();

        $response = $this->post('/api/training/sessions', [
            'discipline' => 'CYCLING',
            'startedAt' => '2020-01-01T00:00:00+00:00',
        ], $account->headers);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());

        $startedAt = self::decode($response)['startedAt'];
        self::assertIsString($startedAt);
        self::assertStringStartsNotWith('2020', $startedAt);
    }

    public function testRejectsAnUnknownDiscipline(): void
    {
        $account = $this->openAccount();

        $response = $this->post('/api/training/sessions', ['discipline' => 'QUIDDITCH'], $account->headers);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));

        $body = self::decode($response);

        self::assertSame('https://grrind.app/problems/validation-failed', $body['type']);
        self::assertIsArray($body['violations']);

        $violation = $body['violations'][0];
        self::assertIsArray($violation);
        self::assertSame('discipline', $violation['field']);
    }

    public function testRejectsAMissingDiscipline(): void
    {
        $account = $this->openAccount();

        $response = $this->post('/api/training/sessions', [], $account->headers);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function testRefusesAnAnonymousCall(): void
    {
        $response = $this->post('/api/training/sessions', ['discipline' => 'RUNNING']);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
    }

    /**
     * L'auteur vient du jeton et de nulle part ailleurs. La vérification passe par la
     * base faute de route de lecture — et le `userId` n'a de toute façon pas à figurer
     * dans la réponse, le porteur du jeton sait déjà qui il est.
     */
    public function testAttributesTheSessionToTheBearer(): void
    {
        $alice = $this->openAccount('alice@grrind.app', 'Alice');
        $bob = $this->openAccount('bob@grrind.app', 'Bob');

        $this->post('/api/training/sessions', ['discipline' => 'CLIMBING'], $bob->headers);
        $session = self::decode($this->post('/api/training/sessions', ['discipline' => 'RUNNING'], $alice->headers));
        self::assertIsString($session['id']);

        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        $owner = $connection->fetchOne(
            'SELECT user_id FROM training_session WHERE id = :id',
            ['id' => $session['id']],
        );

        self::assertSame($alice->id->toRfc4122(), $owner);
    }
}
