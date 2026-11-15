<?php

declare(strict_types=1);

use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;

require dirname(__DIR__).'/bootstrap.php';

if ('test' !== ($_SERVER['APP_ENV'] ?? null)) {
    throw new LogicException('Ce processus de concurrence exige APP_ENV=test.');
}

stream_set_timeout(\STDIN, 15);
$line = fgets(\STDIN);
assert(is_string($line));
$input = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
assert(is_array($input));
assert(is_string($input['uri']));
assert(is_string($input['method']));

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

    $dispatcher = $kernel->getContainer()->get('event_dispatcher');
    assert($dispatcher instanceof EventDispatcherInterface);
    // Les attributs, dont IsGranted, sont exécutés à -10000 (Symfony 8.1).
    $dispatcher->addListener(KernelEvents::CONTROLLER_ARGUMENTS, static function (ControllerArgumentsEvent $event): void {
        if (!$event->isMainRequest()) {
            return;
        }
        echo "authorized\n";
        fflush(\STDOUT);
        if ("continue\n" !== fgets(\STDIN)) {
            throw new RuntimeException('Barrière après voter non libérée.');
        }
    }, -20000);

    $request = Request::create($input['uri'], $input['method'], server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => $input['authorization'],
    ], content: json_encode($input['payload'], \JSON_THROW_ON_ERROR));
    $response = $kernel->handle($request);
    echo 'result:'.json_encode([
        'status' => $response->getStatusCode(),
        'body' => json_decode((string) $response->getContent(), true),
    ], \JSON_THROW_ON_ERROR)."\n";
    fflush(\STDOUT);
    $kernel->terminate($request, $response);
} finally {
    $kernel->shutdown();
}
