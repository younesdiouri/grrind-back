<?php

declare(strict_types=1);

namespace App\Tests\Progression;

use App\Progression\Application\GrantXp;
use App\Progression\Application\GrantXpHandler;
use App\Progression\Infrastructure\Doctrine\ProgressionSnapshotRepository;
use App\Shared\Domain\Activity\Discipline;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /api/progression` : l'écran d'accueil de l'app en une requête.
 *
 * Ce qui compte ici autant que le contenu, c'est **d'où il vient**. La réponse est servie du
 * snapshot, jamais d'un recompte du ledger — un joueur qui ouvre l'app ne doit pas payer la
 * relecture de son historique, et le jour où les deux divergent, c'est la commande de
 * reconstruction (#20) qui doit le dire, pas une réparation silencieuse à chaque affichage.
 */
final class ProgressionStateTest extends ApiTestCase
{
    private GrantXpHandler $grantXp;

    protected function setUp(): void
    {
        parent::setUp();

        $grantXp = self::getContainer()->get(GrantXpHandler::class);
        self::assertInstanceOf(GrantXpHandler::class, $grantXp);
        $this->grantXp = $grantXp;
    }

    public function testANewAccountIsAtTheStartOfTheCurveRatherThanAnError(): void
    {
        $account = $this->openAccount();

        $state = self::decode($this->get('/api/progression', $account->headers));

        // Aucune ligne de progression n'existe encore : l'écran doit s'afficher quand même.
        self::assertSame(1, $state['level']);
        self::assertSame(0, $state['totalXp']);
        self::assertSame(0, $state['xpIntoLevel']);
        self::assertIsInt($state['xpToNextLevel']);
        self::assertSame(['earned' => 0, 'available' => 0], $state['skillPoints']);
        self::assertNull($state['activeTitle']);
        self::assertSame([], $state['unlockedTitles']);
        self::assertNull($state['lastProgressionAt']);

        // Sous quel équilibrage cet état a été projeté. Le client n'en fait rien ; un
        // rapport de bug, si.
        self::assertSame(self::getContainer()->getParameter('game.ruleset_version'), $state['rulesetVersion']);
    }

    public function testReadingTheStateDoesNotCreateTheProgressionRow(): void
    {
        $account = $this->openAccount();

        $this->get('/api/progression', $account->headers);

        // C'est le premier crédit qui pose la ligne, sous verrou. Un `GET` qui écrit, c'est
        // un verrou pris pour rien et une lecture qui cesse d'être rejouable.
        $snapshots = self::getContainer()->get(ProgressionSnapshotRepository::class);
        self::assertInstanceOf(ProgressionSnapshotRepository::class, $snapshots);
        self::assertNull($snapshots->ofPlayer($account->id));
    }

    public function testReportsWhatTheSnapshotHolds(): void
    {
        $account = $this->openAccount();
        $granted = ($this->grantXp)(new GrantXp($account->id, Uuid::v7(), Discipline::Running, 3600, new DateTimeImmutable()));

        $state = self::decode($this->get('/api/progression', $account->headers));

        self::assertSame($granted->award->amount(), $state['totalXp']);
        self::assertSame($granted->snapshot->level(), $state['level']);
        self::assertSame($granted->snapshot->xpIntoLevel(), $state['xpIntoLevel']);
        self::assertSame($granted->snapshot->xpToNextLevel(), $state['xpToNextLevel']);
        self::assertSame(
            ['earned' => $granted->snapshot->earnedSkillPoints(), 'available' => $granted->snapshot->earnedSkillPoints()],
            $state['skillPoints'],
        );
        self::assertIsString($state['lastProgressionAt']);
    }

    public function testIsServedFromTheSnapshotAndNotFromARecountOfTheLedger(): void
    {
        $account = $this->openAccount();
        ($this->grantXp)(new GrantXp($account->id, Uuid::v7(), Discipline::Running, 3600, new DateTimeImmutable()));

        // Une divergence forcée : le snapshot dit une chose, le ledger en dit une autre.
        // Servir le ledger ici rendrait la réponse juste par accident et masquerait
        // exactement ce que la reconstruction (#20) existe pour détecter.
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement('UPDATE progression_snapshot SET total_xp = 4242');

        self::assertSame(4242, self::decode($this->get('/api/progression', $account->headers))['totalXp']);
    }

    public function testListsWhatIsAcquiredWithAFullBarAndItsDate(): void
    {
        $account = $this->openAccount();
        ($this->grantXp)(new GrantXp($account->id, Uuid::v7(), Discipline::Running, 1800, new DateTimeImmutable()));

        $state = self::decode($this->get('/api/progression', $account->headers));

        self::assertIsArray($state['unlockedTitles']);
        self::assertCount(1, $state['unlockedTitles'], 'Une séance ne débloque que `first_steps` sur la balance livrée.');

        $title = $state['unlockedTitles'][0];
        self::assertIsArray($title);
        self::assertSame('first_steps', $title['id']);
        self::assertTrue($title['unlocked']);
        self::assertIsString($title['unlockedAt']);

        // Un déblocage est définitif, donc sa barre est pleine : une barre incomplète sur
        // un titre acquis serait la promesse qu'on peut le perdre.
        self::assertIsArray($title['progress']);
        self::assertSame($title['progress']['target'], $title['progress']['current']);
    }

    public function testShowsTheTitleTheAccountWears(): void
    {
        $account = $this->openAccount();
        ($this->grantXp)(new GrantXp($account->id, Uuid::v7(), Discipline::Running, 1800, new DateTimeImmutable()));

        $this->send('PUT', '/api/titles/active', ['titleId' => 'first_steps'], $account->headers);

        $state = self::decode($this->get('/api/progression', $account->headers));
        self::assertIsArray($state['activeTitle']);
        self::assertSame('first_steps', $state['activeTitle']['id']);

        // La même forme qu'à `GET /api/me` et `GET /api/titles` : un seul type à décoder
        // côté client, et un seul composant à dessiner.
        self::assertIsArray($state['unlockedTitles']);
        self::assertSame($state['unlockedTitles'][0], $state['activeTitle']);
    }

    public function testDoesNotShowTheProgressionOfAnotherAccount(): void
    {
        $mine = $this->openAccount();
        $theirs = $this->openAccount('alice@grrind.app', 'Alice');

        ($this->grantXp)(new GrantXp($theirs->id, Uuid::v7(), Discipline::Running, 3600, new DateTimeImmutable()));

        // Le joueur vient du jeton : aucune route ne prend d'identifiant de compte, donc
        // aucune ne peut être détournée.
        self::assertSame(0, self::decode($this->get('/api/progression', $mine->headers))['totalXp']);
    }

    public function testRequiresAToken(): void
    {
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->get('/api/progression')->getStatusCode());
    }
}
