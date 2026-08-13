<?php

declare(strict_types=1);

namespace App\Tests\Training;

use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Workouts;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /api/workouts/sync-state` — ce que le client demande avant de parler à HealthKit.
 *
 * Le client garde sa date de dernière synchronisation en local pour éviter un aller-retour
 * au démarrage, mais il ne doit pas en **dépendre** : réinstallation, changement d'appareil,
 * second appareil sur le même compte. C'est cette route qui survit à tout ça.
 */
final class SyncStateTest extends ApiTestCase
{
    use Workouts;

    /**
     * Un compte neuf n'a pas de repère : le client demande alors toute la fenêtre.
     */
    public function testAFreshAccountHasNothingToResumeFrom(): void
    {
        $bob = $this->openAccount();

        self::assertSame(
            ['lastImportedAt' => null, 'importWindowDays' => 30],
            $this->syncState($bob),
        );
    }

    /**
     * Le repère est la **fin** du workout le plus récent, pas la date du dernier appel
     * d'import — qui a pu ne rien apporter.
     */
    public function testTheCursorIsTheEndOfTheMostRecentWorkout(): void
    {
        $bob = $this->openAccount();
        $this->recordWorkout($bob, endedAt: new DateTimeImmutable('2026-07-13T08:00:00+00:00'), externalId: 'HK-1');
        $latest = new DateTimeImmutable('2026-07-15T09:30:00+00:00');
        $this->recordWorkout($bob, endedAt: $latest, externalId: 'HK-2');
        $this->recordWorkout($bob, endedAt: new DateTimeImmutable('2026-07-14T08:00:00+00:00'), externalId: 'HK-3');

        $state = $this->syncState($bob);
        self::assertIsString($state['lastImportedAt']);
        self::assertSame(
            $latest->getTimestamp(),
            new DateTimeImmutable($state['lastImportedAt'])->getTimestamp(),
        );
    }

    /**
     * **Les archives comptent, elles aussi.** Un workout hors fenêtre est conservé sans
     * être crédité : le renvoyer ne produirait qu'un `ALREADY_IMPORTED` de plus. Ce que le
     * client cherche, c'est la frontière de ce que le serveur **connaît**, pas celle de ce
     * qu'il a payé.
     */
    public function testAnArchivedWorkoutStillMovesTheCursor(): void
    {
        $bob = $this->openAccount();
        $archived = new DateTimeImmutable('-200 days');
        $this->recordWorkout($bob, endedAt: $archived, externalId: 'HK-ARCHIVE');

        $state = $this->syncState($bob);
        self::assertIsString($state['lastImportedAt']);
        self::assertSame(
            $archived->format(DateTimeInterface::ATOM),
            new DateTimeImmutable($state['lastImportedAt'])->format(DateTimeInterface::ATOM),
        );
    }

    /**
     * La fenêtre est **servie** et non codée en dur : elle doit pouvoir bouger sans
     * publication sur les stores. Une app qui demanderait trente jours pendant que le
     * serveur en accepte soixante enverrait moins que ce qu'elle pourrait.
     */
    public function testTheWindowComesFromTheBalanceAndNotFromTheClient(): void
    {
        self::assertSame(30, $this->syncState($this->openAccount())['importWindowDays']);
    }

    /**
     * L'état d'un joueur n'est jamais celui d'un autre : le compte vient du jeton, et
     * aucune requête ne prend d'identifiant.
     */
    public function testAnAccountNeverSeesAnothersCursor(): void
    {
        $alice = $this->openAccount('alice@grrind.app', 'Alice');
        $bob = $this->openAccount();
        $this->recordWorkout($alice, externalId: 'HK-ALICE');

        self::assertNull($this->syncState($bob)['lastImportedAt']);
        self::assertIsString($this->syncState($alice)['lastImportedAt']);
    }

    public function testRefusesAnonymousCalls(): void
    {
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->get('/api/workouts/sync-state')->getStatusCode());
    }

    /**
     * @return array{lastImportedAt: string|null, importWindowDays: int}
     */
    private function syncState(Account $account): array
    {
        $response = $this->get('/api/workouts/sync-state', $account->headers);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);
        self::assertTrue(null === $body['lastImportedAt'] || \is_string($body['lastImportedAt']));
        self::assertIsInt($body['importWindowDays']);

        /** @var array{lastImportedAt: string|null, importWindowDays: int} $body */
        return $body;
    }
}
