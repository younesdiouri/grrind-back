<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Closure;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/** Injection au vrai COMMIT du pilote, jamais aux RELEASE SAVEPOINT métier. Test uniquement. */
#[AsMiddleware]
final class CommitFaultMiddleware implements Middleware
{
    public static ?Closure $beforeCommit = null;
    public static ?Closure $afterCommit = null;

    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(array $params): Connection
            {
                return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                    public function commit(): void
                    {
                        $before = CommitFaultMiddleware::$beforeCommit;
                        CommitFaultMiddleware::$beforeCommit = null;
                        $before?->__invoke();
                        parent::commit();
                        $after = CommitFaultMiddleware::$afterCommit;
                        CommitFaultMiddleware::$afterCommit = null;
                        $after?->__invoke();
                    }
                };
            }
        };
    }
}
