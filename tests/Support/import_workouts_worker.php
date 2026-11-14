<?php

declare(strict_types=1);

use App\Kernel;
use App\Tests\Support\ProgrammableModifiers;
use App\Training\Domain\Workout;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__).'/bootstrap.php';

if ('test' !== ($_SERVER['APP_ENV'] ?? null)) {
    throw new LogicException('Ce processus de concurrence exige APP_ENV=test.');
}

function barrier(string $stage): void
{
    echo $stage."\n";
    fflush(\STDOUT);
    stream_set_timeout(\STDIN, 15);
    if ("continue\n" !== fgets(\STDIN)) {
        throw new RuntimeException('Barrière non libérée : '.$stage);
    }
}

$kernel = new Kernel('test', true);
$kernel->boot();
$registry = $kernel->getContainer()->get('doctrine');
assert($registry instanceof ManagerRegistry);
$manager = $registry->getManager();
assert($manager instanceof EntityManagerInterface);
$connection = $manager->getConnection();
$connection->executeStatement("SET statement_timeout = '15s'");
$pid = $connection->fetchOne('SELECT pg_backend_pid()');
assert(is_int($pid));
echo 'pid:'.$pid."\n";
fflush(\STDOUT);

// Barrière avant le premier INSERT de workout, donc après les lectures d'arbitrage.
$listener = new class {
    private bool $paused = false;

    public function onFlush(OnFlushEventArgs $event): void
    {
        foreach ($event->getObjectManager()->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if (!$this->paused && $entity instanceof Workout) {
                $this->paused = true;
                barrier('write');
            }
        }
    }
};
$manager->getEventManager()->addEventListener([Events::onFlush], $listener);
stream_set_timeout(\STDIN, 15);
$line = fgets(\STDIN);
assert(is_string($line));
$input = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
assert(is_array($input));
if (isset($input['failAfter'])) {
    assert(is_int($input['failAfter']));
    ProgrammableModifiers::failAfter($input['failAfter']);
}
barrier('ready');

try {
    $request = Request::create('/api/workouts/import', 'POST', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => $input['authorization'],
        'HTTP_IDEMPOTENCY_KEY' => $input['key'],
    ], content: json_encode(['workouts' => $input['workouts']], \JSON_THROW_ON_ERROR));
    $response = $kernel->handle($request);
    echo 'result:'.json_encode(['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true)], \JSON_THROW_ON_ERROR)."\n";
    fflush(\STDOUT);
    $kernel->terminate($request, $response);
} finally {
    $kernel->shutdown();
}
