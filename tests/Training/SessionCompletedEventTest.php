<?php

declare(strict_types=1);

namespace App\Tests\Training;

use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Activity\SessionSource;
use App\Shared\Domain\Activity\TrustLevel;
use App\Shared\Domain\Event\TrainingSessionCompleted;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Messaging\SessionCompletedSpy;
use App\Tests\Support\TrainingSessions;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * `Training` ne connaît pas `Progression` — Deptrac l'interdit, et c'est ce qui
 * empêchera le moteur de pourrir. La complétion publie donc un fait, elle n'appelle
 * personne.
 */
final class SessionCompletedEventTest extends ApiTestCase
{
    use TrainingSessions;

    protected function setUp(): void
    {
        parent::setUp();
        SessionCompletedSpy::forget();
    }

    public function testCompletingASessionLeavesExactlyOneMessageInTheOutbox(): void
    {
        $bob = $this->openAccount();
        $session = $this->startSession($bob);
        $this->ageSession($session, 1800);

        self::assertSame(0, $this->outboxSize());

        $response = $this->completeSession($bob, $session);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        self::assertSame(1, $this->outboxSize());
    }

    /**
     * Le payload est autoportant : un abonné n'a aucune raison de rappeler `Training`.
     * `durationSeconds` en particulier est la durée **retenue** — la recalculer depuis
     * les deux dates recréditerait un chronomètre oublié.
     */
    public function testTheEventCarriesTheWholeFact(): void
    {
        $bob = $this->openAccount();
        $session = $this->startSession($bob, 'CYCLING');
        $this->ageSession($session, 1800);
        $this->completeSession($bob, $session);

        $envelopes = iterator_to_array($this->outbox()->get());
        self::assertCount(1, $envelopes);

        $event = $envelopes[0]->getMessage();
        self::assertInstanceOf(TrainingSessionCompleted::class, $event);

        self::assertSame($session, $event->sessionId->toRfc4122());
        self::assertTrue($bob->id->equals($event->userId));
        self::assertSame(Discipline::Cycling, $event->discipline);
        self::assertSame(SessionSource::ManualTimer, $event->source);
        self::assertSame(TrustLevel::Declared, $event->trust);
        self::assertEqualsWithDelta(1800, $event->durationSeconds, 2);
        self::assertEquals($event->endedAt, $event->occurredAt());
        self::assertGreaterThan($event->startedAt, $event->endedAt);
    }

    /**
     * Le « fini quand » du ticket : un module tiers réagit sans qu'aucune ligne de
     * `Training` ne le mentionne. Le spion n'est ni déclaré ni référencé nulle part —
     * il porte un `#[AsMessageHandler]` et un type-hint sur l'événement de `Shared`.
     */
    public function testAThirdPartyModuleReactsWithoutBeingNamedAnywhere(): void
    {
        $bob = $this->openAccount();
        $session = $this->startSession($bob);
        $this->ageSession($session, 1800);
        $this->completeSession($bob, $session);

        self::assertCount(0, SessionCompletedSpy::$received, 'L\'outbox est asynchrone : rien n\'est traité avant le worker.');

        $this->consumeTheOutbox();

        $received = SessionCompletedSpy::$received;
        self::assertCount(1, $received);
        self::assertSame($session, $received[0]->sessionId->toRfc4122());
        self::assertSame(0, $this->outboxSize());
    }

    /**
     * Un abandon n'apprend rien à personne : la séance ne compte pas, il n'y a pas de
     * fait à publier.
     */
    public function testAnAbandonPublishesNothing(): void
    {
        $bob = $this->openAccount();
        $session = $this->startSession($bob);
        $this->ageSession($session, 1800);

        $this->abandonSession($bob, $session);

        self::assertSame(0, $this->outboxSize());
    }

    /**
     * Le refus laisse la base intacte, événement compris : pas de fait, pas de message.
     * C'est la moitié observable de l'atomicité — l'autre, le COMMIT partiel, ne peut
     * pas se produire puisque les deux écritures partagent la transaction.
     */
    public function testARefusedCompletionPublishesNothing(): void
    {
        $bob = $this->openAccount();
        $session = $this->startSession($bob);

        $response = $this->completeSession($bob, $session);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame(0, $this->outboxSize());
    }

    /**
     * Le rejeu d'une clé d'idempotence rend la réponse conservée sans réexécuter la
     * règle : sans ça, l'outbox contiendrait deux fois le même fait et le joueur
     * gagnerait son XP en double au Lot 4.
     */
    public function testAReplayedRequestDoesNotPublishTwice(): void
    {
        $bob = $this->openAccount();
        $session = $this->startSession($bob);
        $this->ageSession($session, 1800);

        $key = ['Idempotency-Key' => 'b9d2f4a1-6c37-4e28-9a51-0d7e3b8c2145'];
        $uri = \sprintf('/api/training/sessions/%s/complete', $session);

        $this->post($uri, [], $bob->headers + $key);
        $this->post($uri, [], $bob->headers + $key);

        self::assertSame(1, $this->outboxSize());
    }

    /**
     * Le vrai worker, celui du `compose.yaml`, sur un message et pas un de plus : c'est
     * la seule façon de prouver que le routage va bien de l'événement à un abonné qui
     * ne l'a jamais déclaré.
     */
    private function consumeTheOutbox(): void
    {
        $application = new Application(self::bootedKernel());
        $application->setAutoExit(false);

        $tester = new CommandTester($application->find('messenger:consume'));
        $status = $tester->execute([
            'receivers' => ['outbox'],
            '--limit' => 1,
            '--time-limit' => 10,
        ]);

        self::assertSame(0, $status, $tester->getDisplay());
    }

    private static function bootedKernel(): KernelInterface
    {
        $kernel = self::$kernel;
        self::assertInstanceOf(KernelInterface::class, $kernel);

        return $kernel;
    }

    private function outbox(): TransportInterface
    {
        $transport = self::getContainer()->get('messenger.transport.outbox');
        self::assertInstanceOf(TransportInterface::class, $transport);

        return $transport;
    }

    /**
     * Compté en base et non par `TransportInterface::get()`, qui ne rend qu'un message
     * à la fois : c'est le nombre de faits en attente qu'on vérifie, pas ce qu'un
     * worker recevrait au prochain tour.
     */
    private function outboxSize(): int
    {
        $pending = $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = :queue',
            ['queue' => 'default'],
        );

        self::assertIsNumeric($pending);

        return (int) $pending;
    }
}
