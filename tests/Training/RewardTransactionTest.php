<?php

declare(strict_types=1);

namespace App\Tests\Training;

use App\Progression\Application\GrantXp;
use App\Progression\Application\GrantXpHandler;
use App\Shared\Domain\Activity\Discipline;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\TrainingSessions;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * La transaction de complétion contre la vraie base : un seul COMMIT, ou rien.
 *
 * Ce qui se démontre ici ne se démontre nulle part ailleurs. Les tests du Lot 3 prouvent
 * que le crédit est juste ; ceux-ci prouvent qu'il est **lié à la séance** — que fermer une
 * séance écrit au ledger dans le même COMMIT, et qu'un échec en aval ne laisse ni séance
 * close, ni XP, ni événement dans l'outbox.
 *
 * L'échec est provoqué par la base elle-même plutôt que par un service de test qui lèverait
 * sur commande : `uniq_xp_transaction_source_reason` est précisément le garde-fou qui
 * arbitre deux complétions simultanées, et un double le remplacerait par une mise en scène.
 */
final class RewardTransactionTest extends ApiTestCase
{
    use TrainingSessions;

    private const int ELAPSED = 1800;

    private GrantXpHandler $grantXp;

    protected function setUp(): void
    {
        parent::setUp();

        $grantXp = self::getContainer()->get(GrantXpHandler::class);
        self::assertInstanceOf(GrantXpHandler::class, $grantXp);
        $this->grantXp = $grantXp;
    }

    public function testCompletingASessionCreditsTheLedgerInTheSameCommit(): void
    {
        $bob = $this->openAccount();
        $session = $this->runningSession($bob);

        $response = $this->completeSession($bob, $session);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        // Une demi-heure de course à 90 XP l'heure, sans aucun modificateur actif ni
        // rendement décroissant déclenché : le socle seul, et il est au ledger.
        self::assertSame(45, $this->creditedFor($session));

        // Le snapshot est reprojeté dans la foulée — c'est ce que le client lira ensuite
        // sur `GET /api/progression`, sans avoir à attendre un consommateur de l'outbox.
        self::assertSame(45, $this->snapshotTotalOf($bob));
    }

    /**
     * Le chemin d'échec, et c'est le test qui compte : une exception après l'écriture du
     * ledger ne doit laisser **rien**.
     */
    public function testAFailedCreditLeavesNeitherSessionNorXpNorEvent(): void
    {
        $bob = $this->openAccount();
        $session = $this->runningSession($bob);

        // La séance est créditée d'avance, par le chemin normal : sa complétion butera
        // donc sur l'unicité du couple (source, raison), au milieu de la transaction.
        ($this->grantXp)(new GrantXp($bob->id, Uuid::fromString($session), Discipline::Running, self::ELAPSED));

        $ledgerBefore = $this->ledgerSize();
        $totalBefore = $this->snapshotTotalOf($bob);
        $outboxBefore = $this->outboxSize();

        $response = $this->completeSession($bob, $session);

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());

        // La séance n'a pas été close : l'UPDATE faisait partie de la même transaction.
        self::assertSame('ACTIVE', $this->statusOf($session));

        // Ni XP, ni projection, ni événement. Le rollback porte sur tout, y compris sur
        // ce que le transport Doctrine avait inséré dans l'outbox.
        self::assertSame($ledgerBefore, $this->ledgerSize());
        self::assertSame($totalBefore, $this->snapshotTotalOf($bob));
        self::assertSame($outboxBefore, $this->outboxSize());
    }

    /**
     * Deux complétions de la même séance ne créditent jamais deux fois — le second appel
     * porte une clé d'idempotence neuve, donc rien ne le court-circuite avant le domaine.
     */
    public function testASessionIsNeverCreditedTwice(): void
    {
        $bob = $this->openAccount();
        $session = $this->runningSession($bob);

        $first = $this->completeSession($bob, $session);
        $second = $this->completeSession($bob, $session);

        self::assertSame(Response::HTTP_OK, $first->getStatusCode(), (string) $first->getContent());
        self::assertSame(Response::HTTP_CONFLICT, $second->getStatusCode());

        self::assertSame(1, $this->ledgerSize());
        self::assertSame(45, $this->snapshotTotalOf($bob));
    }

    /**
     * Le rejeu du client mobile, celui pour lequel `#[Idempotent]` existe : même clé, même
     * réponse, et une seule écriture au ledger.
     */
    public function testReplayingTheSameKeyDoesNotCreditAgain(): void
    {
        $bob = $this->openAccount();
        $session = $this->runningSession($bob);
        $key = ['Idempotency-Key' => Uuid::v4()->toRfc4122()];

        $path = \sprintf('/api/training/sessions/%s/complete', $session);
        $first = $this->post($path, [], $bob->headers + $key);
        $replayed = $this->post($path, [], $bob->headers + $key);

        self::assertSame(Response::HTTP_OK, $first->getStatusCode(), (string) $first->getContent());
        self::assertSame($first->getContent(), $replayed->getContent());
        self::assertSame(1, $this->ledgerSize());
    }

    /**
     * L'événement part toujours, et il part **après** le crédit : un abonné réveillé plus
     * tôt lirait une progression qui n'existe pas encore.
     */
    public function testTheCompletionEventIsStillPublished(): void
    {
        $bob = $this->openAccount();
        $session = $this->runningSession($bob);

        $this->completeSession($bob, $session);

        self::assertSame(1, $this->outboxSize());
    }

    private function runningSession(Account $account): string
    {
        $id = $this->startSession($account);
        $this->ageSession($id, self::ELAPSED);

        return $id;
    }

    private function creditedFor(string $sessionId): int
    {
        return self::asInt($this->connection()->fetchOne(
            'SELECT amount FROM xp_transaction WHERE source_id = :id AND reason = :reason',
            ['id' => $sessionId, 'reason' => 'SESSION_COMPLETED'],
        ));
    }

    private function ledgerSize(): int
    {
        return self::asInt($this->connection()->fetchOne('SELECT COUNT(*) FROM xp_transaction'));
    }

    private function outboxSize(): int
    {
        return self::asInt($this->connection()->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
    }

    /** La ligne existe : c'est `lockFor()` qui la crée, au premier crédit du joueur. */
    private function snapshotTotalOf(Account $account): int
    {
        return self::asInt($this->connection()->fetchOne(
            'SELECT total_xp FROM progression_snapshot WHERE user_id = :id',
            ['id' => $account->id->toRfc4122()],
        ));
    }

    private static function asInt(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }
}
