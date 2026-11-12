<?php

declare(strict_types=1);

use App\Identity\Domain\RefreshToken;
use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__).'/bootstrap.php';

if ('test' !== ($_SERVER['APP_ENV'] ?? null)) {
    throw new LogicException('Ce processus de concurrence exige APP_ENV=test.');
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

$listener = new class {
    private bool $loaded = false;
    private bool $flushed = false;

    public function postLoad(PostLoadEventArgs $event): void
    {
        if (!$this->loaded && $event->getObject() instanceof RefreshToken) {
            $this->loaded = true;
            $this->pause('loaded');
        }
    }

    public function onFlush(): void
    {
        if (!$this->flushed) {
            $this->flushed = true;
            $this->pause('flush');
        }
    }

    private function pause(string $stage): void
    {
        echo $stage."\n";
        fflush(\STDOUT);
        stream_set_timeout(\STDIN, 15);
        if ("continue\n" !== fgets(\STDIN)) {
            throw new RuntimeException('Barrière non libérée : '.$stage);
        }
    }
};
$manager->getEventManager()->addEventListener([Events::postLoad, Events::onFlush], $listener);
stream_set_timeout(\STDIN, 15);
$payload = fgets(\STDIN);
assert(is_string($payload));
$operation = $argv[1] ?? '';
assert(in_array($operation, ['refresh', 'logout'], true));

try {
    $request = Request::create('/api/auth/'.$operation, 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: trim($payload));
    $response = $kernel->handle($request);
    echo 'result:'.json_encode(['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true)], \JSON_THROW_ON_ERROR)."\n";
    fflush(\STDOUT);
    $kernel->terminate($request, $response);
} finally {
    $kernel->shutdown();
}
