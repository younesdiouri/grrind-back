<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Notifier;

use App\Shared\Infrastructure\Doctrine\PendingPushReceiptRepository;
use App\Shared\Infrastructure\Notifier\CheckExpoPushReceipts;
use App\Shared\Infrastructure\Notifier\CheckExpoPushReceiptsHandler;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Le #131 : interroger les reçus Expo, et router le résultat vers la décision déjà écrite au
 * #140 — jamais en écrire une seconde.
 *
 * {@see self::testAnAbsentReceiptNeitherDiscardsTheDeviceNorDeletesTheLine()} est le test qui
 * compte le plus, au même titre que « `DeviceNotRegistered` supprime, une panne ne supprime
 * pas » l'était au #140 — voir le docblock de `CheckExpoPushReceiptsHandler`.
 */
final class CheckExpoPushReceiptsHandlerTest extends ApiTestCase
{
    public function testADeviceNotRegisteredReceiptDiscardsTheDeviceAndConsumesTheLine(): void
    {
        $this->registerDevice('dead-token');
        $this->recordTicket('ticket-dead', 'dead-token');

        $this->respondWith(['ticket-dead' => ['status' => 'error', 'message' => 'not registered', 'details' => ['error' => 'DeviceNotRegistered']]]);
        $this->handler()(new CheckExpoPushReceipts());

        self::assertSame(0, $this->deviceCount('dead-token'), 'DeviceNotRegistered doit effacer le jeton, sèchement.');
        self::assertSame(0, $this->pendingReceiptCount(), 'Un reçu obtenu — favorable ou non — consomme la ligne.');
    }

    /**
     * Le pendant obligatoire : un refus qui n'affirme rien sur l'appareil (débit, credentials,
     * réseau) ne doit jamais effacer un jeton vivant. Voir la même exigence côté ticket
     * d'envoi dans `ExpoPushSenderTest::testANonFatalRejectionDoesNotDiscardTheToken()`.
     */
    public function testANonFatalRejectionDoesNotDiscardTheDevice(): void
    {
        $this->registerDevice('rate-limited-token');
        $this->recordTicket('ticket-rate', 'rate-limited-token');

        $this->respondWith(['ticket-rate' => ['status' => 'error', 'message' => 'rate exceeded', 'details' => ['error' => 'MessageRateExceeded']]]);
        $this->handler()(new CheckExpoPushReceipts());

        self::assertSame(1, $this->deviceCount('rate-limited-token'));
        self::assertSame(0, $this->pendingReceiptCount());
    }

    public function testAnOkReceiptConsumesTheLineWithoutTouchingTheDevice(): void
    {
        $this->registerDevice('healthy-token');
        $this->recordTicket('ticket-ok', 'healthy-token');

        $this->respondWith(['ticket-ok' => ['status' => 'ok']]);
        $this->handler()(new CheckExpoPushReceipts());

        self::assertSame(1, $this->deviceCount('healthy-token'));
        self::assertSame(0, $this->pendingReceiptCount());
    }

    /**
     * Le test qui compte le plus dans ce ticket : Expo n'a peut-être pas encore produit le
     * reçu — le délai n'est qu'une recommandation, pas une garantie. Rien ne doit en être
     * conclu : ni l'appareil n'est effacé, ni la ligne n'est supprimée. Elle attend une
     * nouvelle tentative, prouvée par {@see self::testAStillFreshAbsentReceiptSchedulesAnotherCheck()}.
     */
    public function testAnAbsentReceiptNeitherDiscardsTheDeviceNorDeletesTheLine(): void
    {
        $this->registerDevice('not-yet-token');
        $this->recordTicket('ticket-not-yet', 'not-yet-token');

        $this->respondWith([]); // Expo ne dit encore rien de ce ticket.
        $this->handler()(new CheckExpoPushReceipts());

        self::assertSame(1, $this->deviceCount('not-yet-token'), 'Un reçu absent ne prouve rien sur l\'appareil.');
        self::assertSame(1, $this->pendingReceiptCount(), 'Un reçu absent ne prouve rien non plus sur le ticket : rien à supprimer.');
    }

    /**
     * Une panne réseau retombe dans le même cas qu'un reçu absent — jamais une invalidation,
     * jamais une ligne perdue : voir le docblock du handler, `fetchReceipts()`.
     */
    public function testANetworkFailureNeitherDiscardsTheDeviceNorDeletesTheLine(): void
    {
        $this->registerDevice('flaky-network-token');
        $this->recordTicket('ticket-flaky', 'flaky-network-token');

        $this->httpClient()->setResponseFactory(static fn (): MockResponse => new MockResponse('', ['http_code' => 500]));
        $this->handler()(new CheckExpoPushReceipts());

        self::assertSame(1, $this->deviceCount('flaky-network-token'));
        self::assertSame(1, $this->pendingReceiptCount());
    }

    /**
     * Un reçu absent, mais encore dans la fenêtre où Expo peut le produire, doit se retenter
     * — un message différé, jamais une boucle d'attente.
     */
    public function testAStillFreshAbsentReceiptSchedulesAnotherCheck(): void
    {
        $this->registerDevice('retry-token');
        $this->recordTicket('ticket-retry', 'retry-token');

        $before = $this->scheduledChecksCount();

        $this->respondWith([]);
        $this->handler()(new CheckExpoPushReceipts());

        self::assertSame($before + 1, $this->scheduledChecksCount(), 'Un reçu absent, pas encore expiré, doit reprogrammer une vérification.');
    }

    /**
     * Passé la fenêtre où Expo garde ses reçus (24h, documenté), un reçu qui n'est toujours
     * pas arrivé ne le sera plus jamais : ce handler cesse de retenter, mais ne supprime pas
     * la ligne pour autant — elle attend une tâche de rétention (#43), voir le docblock de
     * `PendingPushReceipt`.
     */
    public function testAnExpiredAbsentReceiptStopsRetryingWithoutDeletingTheLine(): void
    {
        $this->registerDevice('long-gone-token');
        $this->recordTicket('ticket-long-gone', 'long-gone-token');
        $this->backdateTicket('ticket-long-gone', '-25 hours');

        $before = $this->scheduledChecksCount();

        $this->respondWith([]);
        $this->handler()(new CheckExpoPushReceipts());

        self::assertSame($before, $this->scheduledChecksCount(), 'Passé le délai que documente Expo, ce handler doit abandonner plutôt que retenter indéfiniment.');
        self::assertSame(1, $this->pendingReceiptCount(), 'Abandonner ne veut pas dire supprimer : voir le docblock de PendingPushReceipt.');
    }

    /**
     * Le cœur du garde-fou « à l'échelle d'une guilde » : plusieurs tickets en attente ne
     * doivent produire qu'un seul appel à `getReceipts`, jamais un par ticket.
     */
    public function testSeveralPendingTicketsProduceASingleHttpCall(): void
    {
        $this->registerDevice('token-a');
        $this->registerDevice('token-b', 'bob-b@grrind.app');
        $this->registerDevice('token-c', 'bob-c@grrind.app');
        $this->recordTicket('ticket-a', 'token-a');
        $this->recordTicket('ticket-b', 'token-b');
        $this->recordTicket('ticket-c', 'token-c');

        $requests = 0;
        $this->httpClient()->setResponseFactory(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse(json_encode(['data' => [
                'ticket-a' => ['status' => 'ok'],
                'ticket-b' => ['status' => 'ok'],
                'ticket-c' => ['status' => 'ok'],
            ]], \JSON_THROW_ON_ERROR));
        });

        $this->handler()(new CheckExpoPushReceipts());

        self::assertSame(1, $requests, 'Trois tickets mûrs en même temps ne doivent produire qu\'un seul appel Expo.');
        self::assertSame(0, $this->pendingReceiptCount());
    }

    /** Rien à interroger, rien à appeler — un déclencheur qui arrive trop tôt ne fait rien. */
    public function testNoDueTicketMakesNoHttpCall(): void
    {
        $this->registerDevice('too-fresh-token');
        // Créé à l'instant, sans le recul qu'applique `recordTicket()` : encore loin des
        // 15 minutes recommandées par Expo, donc pas mûr.
        $this->pendingReceipts()->record('ticket-too-fresh', 'too-fresh-token', new DateTimeImmutable());

        $requests = 0;
        $this->httpClient()->setResponseFactory(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse(json_encode(['data' => []], \JSON_THROW_ON_ERROR));
        });

        $this->handler()(new CheckExpoPushReceipts());

        self::assertSame(0, $requests);
        self::assertSame(1, $this->pendingReceiptCount());
    }

    private function registerDevice(string $pushToken, string $email = 'bob@grrind.app'): Account
    {
        $account = $this->openAccount($email, 'Bob');

        $response = $this->post('/api/devices', [
            'pushToken' => $pushToken,
            'platform' => 'IOS',
            'environment' => 'PRODUCTION',
        ], $account->headers);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        return $account;
    }

    /** Recule `created_at` de plus que le délai recommandé, pour que le ticket soit « mûr ». */
    private function recordTicket(string $ticketId, string $pushToken): void
    {
        $this->pendingReceipts()->record($ticketId, $pushToken, new DateTimeImmutable());
        $this->backdateTicket($ticketId, \sprintf('-%d minutes', CheckExpoPushReceipts::DELAY_MINUTES + 1));
    }

    /** `$modifier` est un décalage relatif à `strtotime()`, ex. `-25 hours`. */
    private function backdateTicket(string $ticketId, string $modifier): void
    {
        $offsetSeconds = strtotime($modifier, 0);
        self::assertIsInt($offsetSeconds);

        $this->connection()->executeStatement(
            "UPDATE shared_pending_push_receipt SET created_at = created_at + (:offset || ' seconds')::interval WHERE ticket_id = :ticketId",
            ['offset' => $offsetSeconds, 'ticketId' => $ticketId],
        );
    }

    /**
     * @param array<string, array{status: string, message?: string, details?: array{error?: string}}> $data
     */
    private function respondWith(array $data): void
    {
        $this->httpClient()->setResponseFactory(static fn (): MockResponse => new MockResponse(json_encode(['data' => $data], \JSON_THROW_ON_ERROR)));
    }

    private function handler(): CheckExpoPushReceiptsHandler
    {
        $handler = self::getContainer()->get(CheckExpoPushReceiptsHandler::class);
        self::assertInstanceOf(CheckExpoPushReceiptsHandler::class, $handler);

        return $handler;
    }

    private function pendingReceipts(): PendingPushReceiptRepository
    {
        $repository = self::getContainer()->get(PendingPushReceiptRepository::class);
        self::assertInstanceOf(PendingPushReceiptRepository::class, $repository);

        return $repository;
    }

    private function httpClient(): MockHttpClient
    {
        $client = self::getContainer()->get(HttpClientInterface::class);
        self::assertInstanceOf(MockHttpClient::class, $client);

        return $client;
    }

    private function deviceCount(string $pushToken): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM identity_user_device WHERE push_token = :pushToken', ['pushToken' => $pushToken]);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function pendingReceiptCount(): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM shared_pending_push_receipt');
        self::assertIsNumeric($count);

        return (int) $count;
    }

    /** Ce qui est déjà en file pour `CheckExpoPushReceipts`, `DelayStamp` compris. */
    private function scheduledChecksCount(): int
    {
        $count = $this->connection()->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE body LIKE '%CheckExpoPushReceipts%'");
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
