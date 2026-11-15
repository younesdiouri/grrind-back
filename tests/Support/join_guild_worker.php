<?php

declare(strict_types=1);

use App\Community\Domain\GuildInviteCode;
use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__).'/bootstrap.php';

if ('test' !== ($_SERVER['APP_ENV'] ?? null)) {
    throw new LogicException('Ce processus de concurrence exige APP_ENV=test.');
}

stream_set_timeout(\STDIN, 15);
$line = fgets(\STDIN);
assert(is_string($line));
$input = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
assert(is_array($input));
assert(is_string($input['clockPath']));

// Le parent avance cette horloge seulement une fois l'attente PostgreSQL constatée.
Clock::set(new class($input['clockPath']) implements ClockInterface {
    public function __construct(private readonly string $path)
    {
    }

    public function now(): DateTimeImmutable
    {
        $instant = file_get_contents($this->path);
        assert(is_string($instant));

        return new MockClock(new DateTimeImmutable($instant))->now();
    }
});

$kernel = new Kernel('test', true);
$kernel->boot();
try {
    $registry = $kernel->getContainer()->get('doctrine');
    assert($registry instanceof ManagerRegistry);
    $manager = $registry->getManager();
    assert($manager instanceof EntityManagerInterface);
    $connection = $manager->getConnection();
    $connection->executeStatement("SET statement_timeout = '15s'");
    $pid = $connection->fetchOne('SELECT pg_backend_pid()');
    assert(is_int($pid));
    echo 'pid:'.$pid."\n";

    // L'invitation ET les adhésions sont déjà dans l'identity map avant l'attente.
    $invite = $manager->getRepository(GuildInviteCode::class)->findOneBy(['code' => $input['code']]);
    assert($invite instanceof GuildInviteCode);
    $invite->guild()->members();
    echo "ready\n";
    fflush(\STDOUT);
    if ("continue\n" !== fgets(\STDIN)) {
        throw new RuntimeException('Barrière du join non libérée.');
    }

    $request = Request::create('/api/guilds/join', 'POST', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => $input['authorization'],
    ], content: json_encode(['code' => $input['code']], \JSON_THROW_ON_ERROR));
    $response = $kernel->handle($request);
    echo 'result:'.json_encode(['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true)], \JSON_THROW_ON_ERROR)."\n";
    fflush(\STDOUT);
    $kernel->terminate($request, $response);
} finally {
    $kernel->shutdown();
}
