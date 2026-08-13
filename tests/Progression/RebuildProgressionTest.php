<?php

declare(strict_types=1);

namespace App\Tests\Progression;

use App\Progression\Application\GrantXp;
use App\Progression\Application\GrantXpHandler;
use App\Shared\Domain\Activity\Discipline;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\ArgumentResolver\ArgumentResolverInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Uid\Uuid;

/**
 * `app:progression:rebuild` : la preuve que `progression_snapshot` est bien un cache.
 *
 * Les snapshots sont abîmés ici **en SQL brut**, sans passer par l'entité. C'est volontaire :
 * un bug de projection ne se manifeste pas autrement — il laisse des colonnes qu'aucun
 * mutateur n'a écrites, et c'est précisément ce qu'aucun test passant par le domaine ne
 * saurait simuler.
 */
final class RebuildProgressionTest extends ApiTestCase
{
    private GrantXpHandler $grantXp;

    protected function setUp(): void
    {
        parent::setUp();

        $grantXp = self::getContainer()->get(GrantXpHandler::class);
        self::assertInstanceOf(GrantXpHandler::class, $grantXp);
        $this->grantXp = $grantXp;
    }

    public function testACoherentBaseIsReportedAsSuchAndRewritesNothing(): void
    {
        $account = $this->openAccount();
        $this->earnXp($account);
        $before = $this->snapshotOf($account->id);

        $rebuild = $this->rebuild();

        $rebuild->assertCommandIsSuccessful();
        self::assertStringContainsString('1 comptes vérifiés, aucun écart', self::flatten($rebuild));

        // `updated_at` sort dans `GET /api/progression` sous le nom `lastProgressionAt`.
        // Réécrire « pour être sûr » ferait dire à l'écran d'un joueur qu'il a progressé
        // le jour où on a passé une commande de maintenance.
        self::assertSame($before, $this->snapshotOf($account->id));
    }

    public function testDryRunNamesTheDriftedColumnWithoutRepairingIt(): void
    {
        $account = $this->openAccount();
        $this->earnXp($account);
        $this->corrupt($account->id, 'total_xp', 4242);

        $rebuild = $this->rebuild(['--dry-run' => true]);

        // Sortie non nulle : la commande est faite pour tourner en tâche planifiée, et une
        // sonde qui rend toujours zéro ne sonde rien.
        self::assertSame(Command::FAILURE, $rebuild->getStatusCode());

        $display = self::flatten($rebuild);
        self::assertStringContainsString($account->id->toRfc4122(), $display);
        self::assertStringContainsString('totalXp', $display, 'Le rapport doit désigner la colonne : c\'est elle qui pointe le code à relire.');
        self::assertStringContainsString('4242', $display);
        self::assertStringContainsString('1 comptes vérifiés, 1 en écart', $display);

        self::assertSame(4242, $this->snapshotOf($account->id)['total_xp'], '`--dry-run` a écrit.');
    }

    public function testRepairsASnapshotThatWasAlteredByHand(): void
    {
        $account = $this->openAccount();
        $this->earnXp($account);
        $sane = $this->snapshotOf($account->id);

        // Un total juste et des colonnes projetées fausses : le cas le plus vicieux, celui
        // où le joueur lit la bonne XP et le mauvais nombre de points à dépenser.
        $this->corrupt($account->id, 'level', 99);
        $this->corrupt($account->id, 'earned_skill_points', 99);

        $rebuild = $this->rebuild();

        // Réparer *est* le travail : la sortie est zéro même quand il a fallu réécrire.
        $rebuild->assertCommandIsSuccessful();
        self::assertStringContainsString('1 comptes vérifiés, 1 réécrits', self::flatten($rebuild));

        $repaired = $this->snapshotOf($account->id);
        unset($sane['updated_at'], $repaired['updated_at']);
        self::assertSame($sane, $repaired);

        // Et la commande est stable : une seconde passe ne trouve plus rien.
        $this->rebuild(['--dry-run' => true])->assertCommandIsSuccessful();
    }

    public function testRepairsAnAccountWhoseRowIsMissingAltogether(): void
    {
        $account = $this->openAccount();
        $this->earnXp($account);
        $sane = $this->snapshotOf($account->id);

        $this->connection()->executeStatement(
            'DELETE FROM progression_snapshot WHERE user_id = :id',
            ['id' => $account->id->toRfc4122()],
        );

        // Le compte n'est plus « connu » que par le ledger : le trouver quand même est
        // exactement ce que l'union de `everyKnownPlayer()` achète.
        $this->rebuild()->assertCommandIsSuccessful();

        $repaired = $this->snapshotOf($account->id);
        unset($sane['updated_at'], $repaired['updated_at']);
        self::assertSame($sane, $repaired);
    }

    public function testUserRestrictsThePassToThatOneAccount(): void
    {
        $mine = $this->openAccount();
        $theirs = $this->openAccount('alice@grrind.app', 'Alice');
        $this->earnXp($mine);
        $this->earnXp($theirs);

        $this->corrupt($mine->id, 'total_xp', 4242);
        $this->corrupt($theirs->id, 'total_xp', 4242);

        $rebuild = $this->rebuild(['--user' => $mine->id->toRfc4122()]);

        $rebuild->assertCommandIsSuccessful();
        self::assertStringContainsString('1 comptes vérifiés, 1 réécrits', self::flatten($rebuild));

        self::assertNotSame(4242, $this->snapshotOf($mine->id)['total_xp']);
        self::assertSame(4242, $this->snapshotOf($theirs->id)['total_xp'], 'La passe a débordé sur un compte qu\'on ne lui avait pas demandé.');
    }

    public function testANewAccountWithoutARowIsNotADivergence(): void
    {
        $account = $this->openAccount();

        // Aucune ligne n'existe tant qu'il n'y a pas eu de crédit, et c'est l'état normal
        // d'un compte qui vient de s'inscrire. La passe complète ne le voit même pas : il
        // n'est connu ni de `progression_snapshot` ni du ledger.
        $whole = $this->rebuild(['--dry-run' => true]);

        $whole->assertCommandIsSuccessful();
        self::assertStringContainsString('0 comptes vérifiés', self::flatten($whole));

        // Nommé explicitement, en revanche, il arrive jusqu'à la comparaison — et là non
        // plus il ne doit pas être signalé, ni se voir poser une ligne de zéros.
        $named = $this->rebuild(['--user' => $account->id->toRfc4122()]);

        $named->assertCommandIsSuccessful();
        self::assertStringContainsString('1 comptes vérifiés, aucun écart', self::flatten($named));
        self::assertFalse($this->connection()->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM progression_snapshot WHERE user_id = :id)',
            ['id' => $account->id->toRfc4122()],
        ));
    }

    public function testRefusesAnIdentifierThatIsNotOne(): void
    {
        $rebuild = $this->rebuild(['--user' => 'bob']);

        // Distinct de l'échec : un opérateur qui se trompe de copier-coller doit le lire
        // comme une erreur d'usage, pas comme une base en écart.
        self::assertSame(Command::INVALID, $rebuild->getStatusCode());
        self::assertStringContainsString('n\'est pas un identifiant de compte', self::flatten($rebuild));
    }

    /**
     * La commande, jouée sur le conteneur de test.
     *
     * L'API récente — `runCommand()` et son `ExecutionResult` — ne convient pas ici : son
     * `TestOutput` refuse `section()`, dont `SymfonyStyle::createTable()` se sert pour
     * rendre un tableau. Le testeur historique écrit dans un `StreamOutput` simple, qui
     * n'a pas ce trou. Déformer la sortie de la commande pour contourner une limite du
     * harnais serait le mauvais sens de la dépendance.
     *
     * @param array<string, bool|string> $input
     */
    private function rebuild(array $input = []): CommandTester
    {
        $kernel = self::getContainer()->get('kernel');
        self::assertInstanceOf(KernelInterface::class, $kernel);

        $application = new Application($kernel);

        // Sans lui, les arguments de `__invoke()` — le `SymfonyStyle` comme les options —
        // ne se résolvent pas.
        $arguments = self::getContainer()->get('console.argument_resolver');
        self::assertInstanceOf(ArgumentResolverInterface::class, $arguments);
        $application->setArgumentResolver($arguments);

        $tester = new CommandTester($application->find('app:progression:rebuild'));
        $tester->execute($input);

        return $tester;
    }

    private function earnXp(Account $account): void
    {
        ($this->grantXp)(new GrantXp($account->id, Uuid::v7(), Discipline::Running, 3600, new DateTimeImmutable()));
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotOf(Uuid $userId): array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT * FROM progression_snapshot WHERE user_id = :id',
            ['id' => $userId->toRfc4122()],
        );
        self::assertIsArray($row, 'Aucune ligne de progression pour ce compte.');

        return $row;
    }

    private function corrupt(Uuid $userId, string $column, int $value): void
    {
        $this->connection()->executeStatement(
            \sprintf('UPDATE progression_snapshot SET %s = :value WHERE user_id = :id', $column),
            ['value' => $value, 'id' => $userId->toRfc4122()],
        );
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /**
     * La sortie, sauts de ligne écrasés. `SymfonyStyle` coupe ses blocs à la largeur du
     * terminal : chercher une phrase entière dans la sortie brute ferait dépendre le test
     * de la taille de la fenêtre de celui qui le lance.
     */
    private static function flatten(CommandTester $tester): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }
}
