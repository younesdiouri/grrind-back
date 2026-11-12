<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Tests\Support\ApiTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/** Deux kernels/EntityManagers et des barrières réelles ; aucun sommeil ne décide de l'ordre. */
final class RefreshFamilyConcurrencyTest extends ApiTestCase
{
    /** @var list<Process> */
    private array $workers = [];

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            $worker->stop(0);
        }
        parent::tearDown();
    }

    public function testTwoRotationsOfTheSameSecretAllowOneSuccessThenRevokeTheFamily(): void
    {
        [$ancestor, $current, $family, $other] = $this->families();
        [$first, $firstInput] = $this->worker('refresh', $current);
        [$second, $secondInput] = $this->worker('refresh', $current);
        $firstInput->write("continue\n");
        $this->awaitOutput($first, 'flush');
        $secondInput->write("continue\n");
        $this->awaitBlockedOrFinished($second);
        $firstInput->write("continue\n");
        $firstResult = $this->workerResult($first);
        $secondInput->write("continue\n");
        $secondResult = $this->workerResult($second);

        self::assertSame([200, 401], [$firstResult['status'], $secondResult['status']], $this->familyState($family));
        self::assertSame(3, $this->db()->fetchOne('SELECT COUNT(*) FROM identity_refresh_token WHERE family_id = ?', [$family]));
        self::assertSame(2, $this->db()->fetchOne('SELECT COUNT(*) FROM identity_refresh_token WHERE family_id = ? AND consumed_at IS NOT NULL', [$family]));
        self::assertSame(1, $this->db()->fetchOne('SELECT COUNT(*) FROM identity_refresh_token parent JOIN identity_refresh_token child ON child.id = parent.successor_id WHERE parent.token_hash = ? AND child.consumed_at IS NULL AND child.revoked_at IS NOT NULL', [hash('sha256', $current)]));
        $this->assertRevoked($family, $other);
        $this->assertRefreshRefused($firstResult);
    }

    public function testLogoutWaitsForRotationAndRevokesItsNewDescendant(): void
    {
        [$ancestor, $current, $family, $other] = $this->families();
        [$rotation, $rotationInput] = $this->worker('refresh', $current);
        // Logout présente un autre jeton de la même famille : verrouiller le secret seul ne suffit pas.
        [$logout, $logoutInput] = $this->worker('logout', $ancestor);
        $rotationInput->write("continue\n");
        $this->awaitOutput($rotation, 'flush');
        $logoutInput->write("continue\n");
        $this->awaitBlockedOrFinished($logout);
        // Sur main, logout arrive déjà au COMMIT ; on le termine avant l'INSERT de rotation.
        if (str_contains($logout->getOutput(), "flush\n")) {
            $logoutInput->write("continue\n");
            $this->workerResult($logout);
        }
        $rotationInput->write("continue\n");
        $rotated = $this->workerResult($rotation);
        if ($logout->isRunning()) {
            $logoutInput->write("continue\n");
        }

        self::assertSame(204, $this->workerResult($logout)['status']);
        self::assertSame(200, $rotated['status']);
        self::assertSame(3, $this->db()->fetchOne('SELECT COUNT(*) FROM identity_refresh_token WHERE family_id = ?', [$family]));
        $this->assertRevoked($family, $other);
        $this->assertRefreshRefused($rotated);
    }

    public function testRotationLoadedBeforeLogoutRechecksRevocationAfterWaiting(): void
    {
        [$ancestor, $current, $family, $other] = $this->families();
        [$logout, $logoutInput] = $this->worker('logout', $ancestor);
        [$rotation, $rotationInput] = $this->worker('refresh', $current);
        $logoutInput->write("continue\n");
        $this->awaitOutput($logout, 'flush');
        $rotationInput->write("continue\n");
        $this->awaitBlockedOrFinished($rotation);
        $logoutInput->write("continue\n");
        self::assertSame(204, $this->workerResult($logout)['status']);
        $rotationInput->write("continue\n");

        self::assertSame(401, $this->workerResult($rotation)['status'], $this->familyState($family));
        self::assertSame(2, $this->db()->fetchOne('SELECT COUNT(*) FROM identity_refresh_token WHERE family_id = ?', [$family]));
        $this->assertRevoked($family, $other);
    }

    public function testAnotherDeviceRotatesWhileTheFirstFamilyIsLocked(): void
    {
        [$ancestor, $current, $family, $other] = $this->families();
        [$rotation, $input] = $this->worker('refresh', $current);
        $input->write("continue\n");
        $this->awaitOutput($rotation, 'flush');
        self::assertSame(200, $this->post('/api/auth/refresh', ['refreshToken' => $other])->getStatusCode());
        $input->write("continue\n");
        self::assertSame(200, $this->workerResult($rotation)['status']);
    }

    public function testExpirationIsRecheckedAfterTheFamilyLockWithoutRevokingPush(): void
    {
        [$ancestor, $current, $family, $other] = $this->families();
        [$rotation, $input] = $this->worker('refresh', $current);
        $db = $this->db();
        $db->beginTransaction();
        try {
            $db->executeStatement("SELECT pg_advisory_xact_lock(hashtext('identity.refresh'), hashtext(?))", [$family]);
            $db->executeStatement("UPDATE identity_refresh_token SET expires_at = CURRENT_TIMESTAMP - INTERVAL '1 second' WHERE token_hash = ?", [hash('sha256', $current)]);
            $input->write("continue\n");
            $this->awaitBlockedOrFinished($rotation);
            $db->commit();
        } finally {
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }
        }
        $input->write("continue\n");
        $result = $this->workerResult($rotation);
        self::assertSame(401, $result['status']);
        self::assertIsArray($result['body']);
        self::assertSame('https://grrind.app/problems/invalid-refresh-token', $result['body']['type']);
        self::assertSame(2, $db->fetchOne('SELECT COUNT(*) FROM identity_refresh_token WHERE family_id = ?', [$family]));
        self::assertSame(0, $db->fetchOne('SELECT COUNT(*) FROM identity_refresh_token WHERE family_id = ? AND revoked_at IS NOT NULL', [$family]));
        self::assertSame(['phone', 'watch'], $db->fetchFirstColumn('SELECT push_token FROM identity_user_device ORDER BY push_token'));
        self::assertSame(200, $this->post('/api/auth/refresh', ['refreshToken' => $other])->getStatusCode());
    }

    /** @return array{string, string, string, string} Ancêtre, courant, famille, autre appareil. */
    private function families(): array
    {
        $this->openAccount();
        $sessions = [];
        foreach (['phone', 'watch'] as $device) {
            $body = self::decode($this->post('/api/auth/login', ['email' => 'bob@grrind.app', 'password' => 'un-mot-de-passe-assez-long']));
            self::assertIsArray($body['tokens']);
            self::assertIsString($body['tokens']['refreshToken']);
            self::assertIsString($body['tokens']['accessToken']);
            $sessions[] = $body['tokens']['refreshToken'];
            self::assertSame(200, $this->post('/api/devices', ['pushToken' => $device, 'platform' => 'IOS', 'environment' => 'PRODUCTION'], ['Authorization' => 'Bearer '.$body['tokens']['accessToken']])->getStatusCode());
        }
        $body = self::decode($this->post('/api/auth/refresh', ['refreshToken' => $sessions[0]]));
        self::assertIsArray($body['tokens']);
        self::assertIsString($body['tokens']['refreshToken']);
        $family = $this->db()->fetchOne('SELECT family_id FROM identity_refresh_token WHERE token_hash = ?', [hash('sha256', $sessions[0])]);
        self::assertIsString($family);

        return [$sessions[0], $body['tokens']['refreshToken'], $family, $sessions[1]];
    }

    /** @return array{Process, InputStream} */
    private function worker(string $operation, string $secret): array
    {
        $input = new InputStream();
        $input->write(json_encode(['refreshToken' => $secret], \JSON_THROW_ON_ERROR)."\n");
        $process = new Process([\PHP_BINARY, \dirname(__DIR__).'/Support/refresh_family_worker.php', $operation], \dirname(__DIR__, 2));
        $process->setInput($input);
        $process->setTimeout(20);
        $process->start();
        $this->workers[] = $process;
        $this->awaitOutput($process, 'loaded');

        return [$process, $input];
    }

    private function awaitOutput(Process $process, string $stage): void
    {
        self::assertTrue($process->waitUntil(static fn (): bool => str_contains($process->getOutput(), $stage."\n")), $process->getErrorOutput());
    }

    /** Le concurrent atteint soit sa barrière, soit une attente PostgreSQL observable. */
    private function awaitBlockedOrFinished(Process $process): void
    {
        if (1 !== preg_match('/pid:(\d+)/', $process->getOutput(), $matches)) {
            self::fail('Le processus ne donne pas son PID PostgreSQL.');
        }
        $deadline = microtime(true) + 10;
        do {
            if (str_contains($process->getOutput(), "flush\n") || str_contains($process->getOutput(), 'result:')) {
                return;
            }
            if ($this->db()->fetchOne('SELECT cardinality(pg_blocking_pids(?))', [(int) $matches[1]]) > 0) {
                return;
            }
            $process->checkTimeout();
        } while ($process->isRunning() && microtime(true) < $deadline);
        self::fail('Le concurrent ne rejoint ni la barrière ni une attente de verrou : '.$process->getErrorOutput());
    }

    /** @return array{status: int, body: mixed} */
    private function workerResult(Process $process): array
    {
        self::assertSame(0, $process->wait(), $process->getErrorOutput());
        if (1 !== preg_match('/result:(.+)/', $process->getOutput(), $matches)) {
            self::fail('Le processus ne donne pas sa réponse HTTP.');
        }
        $result = json_decode($matches[1], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertIsInt($result['status']);

        return ['status' => $result['status'], 'body' => $result['body']];
    }

    private function assertRevoked(string $family, string $other): void
    {
        self::assertSame(0, $this->db()->fetchOne('SELECT COUNT(*) FROM identity_refresh_token WHERE family_id = ? AND revoked_at IS NULL', [$family]), $this->familyState($family));
        self::assertSame(['watch'], $this->db()->fetchFirstColumn('SELECT push_token FROM identity_user_device'));
        self::assertSame(200, $this->post('/api/auth/refresh', ['refreshToken' => $other])->getStatusCode());
    }

    /** @param array{status: int, body: mixed} $result */
    private function assertRefreshRefused(array $result): void
    {
        $body = $result['body'];
        self::assertIsArray($body);
        self::assertIsArray($body['tokens']);
        self::assertIsString($body['tokens']['refreshToken']);
        self::assertSame(401, $this->post('/api/auth/refresh', ['refreshToken' => $body['tokens']['refreshToken']])->getStatusCode());
    }

    private function db(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private function familyState(string $family): string
    {
        return json_encode([
            'tokens' => $this->db()->fetchAllAssociative('SELECT id, consumed_at, revoked_at, successor_id FROM identity_refresh_token WHERE family_id = ? ORDER BY id', [$family]),
            'devices' => $this->db()->fetchFirstColumn('SELECT push_token FROM identity_user_device ORDER BY push_token'),
        ], \JSON_THROW_ON_ERROR);
    }
}
