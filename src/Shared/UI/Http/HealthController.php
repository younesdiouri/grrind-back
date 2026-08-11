<?php

declare(strict_types=1);

namespace App\Shared\UI\Http;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Sonde de liveness/readiness, sans authentification : elle ne divulgue rien d'autre que
 * la joignabilité des dépendances.
 */
final readonly class HealthController
{
    public function __construct(private Connection $connection)
    {
    }

    #[Route('/health', name: 'health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $database = $this->checkDatabase();
        $healthy = 'up' === $database;

        return new JsonResponse(
            [
                'status' => $healthy ? 'ok' : 'degraded',
                'checks' => ['database' => $database],
            ],
            $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    private function checkDatabase(): string
    {
        try {
            $this->connection->executeQuery('SELECT 1');

            return 'up';
        } catch (Throwable) {
            return 'down';
        }
    }
}
