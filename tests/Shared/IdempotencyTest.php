<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Shared\Infrastructure\Doctrine\IdempotencyRecordRepository;
use App\Shared\UI\Http\IdempotencyListener;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le mécanisme se teste sur une sonde routée en test seulement : il est transverse et
 * la première écriture métier à le porter — la complétion de séance — n'existe pas
 * encore. Le `runId` de la sonde change à chaque exécution réelle du contrôleur : deux
 * réponses qui portent le même prouvent qu'il n'a tourné qu'une fois.
 */
final class IdempotencyTest extends ApiTestCase
{
    private const string PROBE = '/api/_probe/idempotent';
    private const string KEY = '9f1c2b6e-3a4d-4f7a-9c11-2f7e8b0d5a63';

    public function testReplaysTheFirstResponseWithoutRunningTheControllerAgain(): void
    {
        $bob = $this->openAccount();

        $first = $this->call($bob, ['note' => 'séance du matin']);
        $second = $this->call($bob, ['note' => 'séance du matin']);

        self::assertSame(Response::HTTP_CREATED, $first->getStatusCode());
        self::assertSame(Response::HTTP_CREATED, $second->getStatusCode());
        self::assertSame(self::decode($first)['runId'], self::decode($second)['runId']);
        self::assertSame('/api/_probe/idempotent', $second->headers->get('Location'));

        // Le client sait qu'il relit et n'a pas déclenché un second traitement.
        self::assertNull($first->headers->get(IdempotencyListener::REPLAY_HEADER));
        self::assertSame('true', $second->headers->get(IdempotencyListener::REPLAY_HEADER));
    }

    public function testRefusesTheSameKeyOnADifferentRequest(): void
    {
        $bob = $this->openAccount();
        $this->call($bob, ['note' => 'séance du matin']);

        $response = $this->call($bob, ['note' => 'autre chose']);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertProblem($response, 'idempotency-key-reused');
    }

    public function testRefusesAKeyHeldByARequestStillRunning(): void
    {
        $bob = $this->openAccount();

        // Une requête concurrente ne se simule pas avec un KernelBrowser : on pose donc
        // la réservation comme l'aurait fait l'autre requête, sans jamais la terminer.
        $this->records()->claim($bob->id, self::KEY, str_repeat('a', 64), new DateTimeImmutable());

        $response = $this->call($bob, ['note' => 'séance du matin']);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertProblem($response, 'idempotency-key-in-flight');
    }

    public function testDemandsTheHeaderOnAProtectedWrite(): void
    {
        $bob = $this->openAccount();

        $response = $this->post(self::PROBE, ['note' => 'séance du matin'], $bob->headers);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertProblem($response, 'idempotency-key-required');
    }

    public function testAKeyIsPersonalToItsAccount(): void
    {
        $bob = $this->openAccount();
        $alice = $this->openAccount('alice@grrind.app', 'Alice');

        $hers = $this->call($alice, ['note' => 'séance du matin']);
        $his = $this->call($bob, ['note' => 'séance du matin']);

        // Même clé, même corps, deux comptes : deux exécutions. Sans le scope par
        // compte, Bob recevrait la réponse d'Alice.
        self::assertSame(Response::HTTP_CREATED, $his->getStatusCode());
        self::assertNotSame(self::decode($hers)['runId'], self::decode($his)['runId']);
    }

    public function testReleasesTheKeyWhenTheRequestBlowsUp(): void
    {
        $bob = $this->openAccount();

        // Le client de test laisse remonter l'exception : ici on veut la réponse 500,
        // celle que recevrait un vrai client.
        $this->client->catchExceptions(true);
        $failed = $this->call($bob, ['fail' => true]);

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $failed->getStatusCode());
        self::assertNull($this->records()->ofKey($bob->id, self::KEY));

        // Une panne n'est pas un résultat : la même clé doit pouvoir resservir, sinon
        // le joueur reste bloqué vingt-quatre heures sur une action qui n'a rien écrit.
        $retried = $this->call($bob, ['fail' => false]);

        self::assertSame(Response::HTTP_CREATED, $retried->getStatusCode());
    }

    public function testKeepsBusinessRefusalsReplayable(): void
    {
        $bob = $this->openAccount();

        // Un refus métier est une réponse à part entière, pas une panne : le rejeu doit
        // rendre le même 409 sans repasser par la règle qui l'a produit.
        $refused = $this->call($bob, ['refuse' => true]);
        $replayed = $this->call($bob, ['refuse' => true]);

        self::assertSame(Response::HTTP_CONFLICT, $refused->getStatusCode());
        self::assertSame(Response::HTTP_CONFLICT, $replayed->getStatusCode());
        self::assertSame($refused->getContent(), $replayed->getContent());
        self::assertSame('true', $replayed->headers->get(IdempotencyListener::REPLAY_HEADER));
    }

    public function testAnExpiredKeyBecomesUsableAgain(): void
    {
        $bob = $this->openAccount();

        $records = $this->records();
        $records->claim($bob->id, self::KEY, str_repeat('a', 64), new DateTimeImmutable('-25 hours'));

        $response = $this->call($bob, ['note' => 'séance du matin']);

        // Passé la rétention, le client ne rejoue plus, il refait — et il n'a pas
        // besoin qu'une purge soit passée avant lui.
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        self::assertNull($response->headers->get(IdempotencyListener::REPLAY_HEADER));

        $revived = $this->records()->ofKey($bob->id, self::KEY);
        self::assertNotNull($revived);
        self::assertGreaterThan(new DateTimeImmutable(), $revived->expiresAt());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function call(Account $account, array $payload): Response
    {
        return $this->post(self::PROBE, $payload, $account->headers + ['Idempotency-Key' => self::KEY]);
    }

    private function records(): IdempotencyRecordRepository
    {
        $records = self::getContainer()->get(IdempotencyRecordRepository::class);
        self::assertInstanceOf(IdempotencyRecordRepository::class, $records);

        return $records;
    }

    private static function assertProblem(Response $response, string $type): void
    {
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame('https://grrind.app/problems/'.$type, self::decode($response)['type']);
    }
}
