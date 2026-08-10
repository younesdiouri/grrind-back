<?php

declare(strict_types=1);

namespace App\Tests\Training;

use App\Tests\Support\ApiTestCase;
use App\Tests\Support\TrainingSessions;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Les garde-fous anti-abus, qui sont d'abord des règles de game design : ils rendent la
 * triche sans intérêt plutôt que de la punir.
 *
 * Les seuils lus ici sont ceux de `config/game/v1/training.yaml` — les tests les
 * réaffirment en dur parce qu'un test qui relit la configuration qu'il vérifie ne
 * vérifie rien. Un rééquilibrage doit faire échouer cette suite et forcer à relire ce
 * qu'il change.
 */
final class GuardRailsTest extends ApiTestCase
{
    use TrainingSessions;

    private const int MINIMUM = 300;
    private const int MAXIMUM = 14400;
    private const int COOLDOWN = 900;

    public function testOnlyOneSessionRunsAtATime(): void
    {
        $bob = $this->openAccount();
        $running = $this->startSession($bob);

        $response = $this->post('/api/training/sessions', ['discipline' => 'CYCLING'], $bob->headers);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertProblem($response, 'session-already-active');

        // L'identifiant de la séance en cours part avec l'erreur : le joueur voulait
        // celle-là, il ne le sait simplement plus. Le client la rouvre au lieu de rester
        // devant un refus.
        self::assertSame($running, self::decode($response)['activeSessionId']);
    }

    /**
     * Le contrôle applicatif laisse passer deux requêtes simultanées entre son SELECT
     * et son INSERT — c'est la base qui garantit l'invariant. On l'attaque donc au
     * niveau où la course se joue : deux lignes ACTIVE pour un même joueur.
     */
    public function testTheDatabaseItselfRefusesASecondActiveSession(): void
    {
        $bob = $this->openAccount();
        $this->startSession($bob);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->connection()->executeStatement(
            'INSERT INTO training_session (id, user_id, discipline, source, trust, status, started_at, created_at)
             VALUES (:id, :userId, :discipline, :source, :trust, :status, NOW(), NOW())',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'userId' => $bob->id->toRfc4122(),
                'discipline' => 'CYCLING',
                'source' => 'MANUAL_TIMER',
                'trust' => 'DECLARED',
                'status' => 'ACTIVE',
            ],
        );
    }

    public function testAClosedSessionFreesThePlace(): void
    {
        $bob = $this->openAccount();
        $this->pastSession($bob);

        $response = $this->post('/api/training/sessions', ['discipline' => 'RUNNING'], $bob->headers);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());
    }

    /**
     * Sous le plancher, la clôture est refusée et **la séance reste en cours** : rien
     * n'est perdu, le joueur continue. Requalifier en abandon déciderait à sa place et
     * détruirait la séance d'un appui malheureux à 4 min 59.
     */
    public function testTooShortACompletionIsRefusedWithoutClosingTheSession(): void
    {
        $bob = $this->openAccount();
        $session = $this->startSession($bob);

        $response = $this->completeSession($bob, $session);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertProblem($response, 'session-too-short');

        $body = self::decode($response);
        self::assertSame(self::MINIMUM, $body['minimumDurationSeconds']);
        self::assertIsInt($body['remainingSeconds']);
        self::assertGreaterThan(self::MINIMUM - 60, $body['remainingSeconds']);

        self::assertSame('ACTIVE', $this->statusOf($session));
    }

    public function testTheSameSessionClosesOnceTheFloorIsPassed(): void
    {
        $bob = $this->openAccount();
        $session = $this->startSession($bob);

        self::assertSame(Response::HTTP_CONFLICT, $this->completeSession($bob, $session)->getStatusCode());

        $this->ageSession($session, self::MINIMUM);
        $response = $this->completeSession($bob, $session);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('COMPLETED', self::decode($response)['status']);
    }

    /**
     * L'abandon n'a pas de plancher, et c'est le pendant du refus ci-dessus : il faut
     * une sortie, sinon une séance ouverte par erreur enferme le joueur.
     */
    public function testTheAbandonHasNoFloor(): void
    {
        $bob = $this->openAccount();
        $session = $this->startSession($bob);

        $response = $this->abandonSession($bob, $session);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('ABANDONED', self::decode($response)['status']);
    }

    /**
     * Au plafond, la séance est **écrêtée et non rejetée** : le joueur qui oublie de
     * couper son chrono garde ses quatre heures au lieu de tout perdre.
     */
    public function testAnOverlongSessionIsClippedRatherThanRejected(): void
    {
        $bob = $this->openAccount();
        $session = $this->startSession($bob);
        $this->ageSession($session, 36000);

        $response = $this->completeSession($bob, $session);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);
        self::assertSame(self::MAXIMUM, $body['durationSeconds']);

        // La date de fin, elle, reste celle de l'horloge serveur : c'est la durée
        // *retenue* qui est écrêtée, pas le fait. Les deux ne coïncident plus, et
        // `durationSeconds` est ce qui fait foi.
        self::assertIsString($body['startedAt']);
        self::assertIsString($body['endedAt']);
        $startedAt = new DateTimeImmutable($body['startedAt']);
        $endedAt = new DateTimeImmutable($body['endedAt']);
        self::assertGreaterThan(self::MAXIMUM, $endedAt->getTimestamp() - $startedAt->getTimestamp());
    }

    public function testACooldownSeparatesTwoSessions(): void
    {
        $bob = $this->openAccount();
        $session = $this->startSession($bob);
        $this->ageSession($session, 1800);
        $this->completeSession($bob, $session);

        $response = $this->post('/api/training/sessions', ['discipline' => 'RUNNING'], $bob->headers);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertProblem($response, 'session-cooldown');

        $body = self::decode($response);
        self::assertIsInt($body['remainingSeconds']);
        self::assertGreaterThan(self::COOLDOWN - 60, $body['remainingSeconds']);
        self::assertLessThanOrEqual(self::COOLDOWN, $body['remainingSeconds']);

        // L'instant de disponibilité accompagne le décompte : le client l'affiche sans
        // dépendre de l'heure de l'appareil.
        self::assertIsString($body['readyAt']);
        self::assertInstanceOf(
            DateTimeImmutable::class,
            DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $body['readyAt']),
        );
    }

    public function testTheCooldownRunsOut(): void
    {
        $bob = $this->openAccount();
        $session = $this->startSession($bob);
        $this->ageSession($session, 1800);
        $this->completeSession($bob, $session);
        $this->ageSession($session, self::COOLDOWN);

        $response = $this->post('/api/training/sessions', ['discipline' => 'RUNNING'], $bob->headers);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());
    }

    /**
     * La décision du ticket #8 : une séance abandonnée sous le plancher n'a pas eu lieu.
     * Punir d'un quart d'heure le chronomètre lancé par erreur ferait du garde-fou une
     * punition — et l'abandon est justement la porte de sortie.
     */
    public function testASessionAbandonedUnderTheFloorDoesNotStartTheCooldown(): void
    {
        $bob = $this->openAccount();
        $this->abandonSession($bob, $this->startSession($bob));

        $response = $this->post('/api/training/sessions', ['discipline' => 'RUNNING'], $bob->headers);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());
    }

    /**
     * L'autre moitié de la même décision : au-dessus du plancher, abandonner ne doit
     * pas devenir le moyen d'effacer le cooldown.
     */
    public function testASessionAbandonedAboveTheFloorStartsTheCooldown(): void
    {
        $bob = $this->openAccount();
        $session = $this->startSession($bob);
        $this->ageSession($session, 1800);
        $this->abandonSession($bob, $session);

        $response = $this->post('/api/training/sessions', ['discipline' => 'RUNNING'], $bob->headers);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertProblem($response, 'session-cooldown');
    }

    /**
     * Les garde-fous sont par compte : la séance d'Alice n'empêche rien à Bob, et son
     * cooldown ne court pas sur lui.
     */
    public function testTheGuardRailsAreScopedToTheAccount(): void
    {
        $alice = $this->openAccount('alice@grrind.app', 'Alice');
        $bob = $this->openAccount();

        $hers = $this->startSession($alice);
        $this->ageSession($hers, 1800);
        $this->completeSession($alice, $hers);

        $response = $this->post('/api/training/sessions', ['discipline' => 'RUNNING'], $bob->headers);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());
    }

    private static function assertProblem(Response $response, string $type): void
    {
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame('https://grrind.app/problems/'.$type, self::decode($response)['type']);
    }
}
