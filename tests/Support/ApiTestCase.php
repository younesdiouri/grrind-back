<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base des tests d'API. Chaque test part d'une base vide : les tables sont
 * découvertes dans le schéma plutôt qu'énumérées, pour qu'un nouveau module
 * n'oblige pas à revenir ici.
 */
abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->truncateEverything();
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers noms HTTP usuels, ex. « Authorization »
     */
    protected function post(string $uri, array $payload = [], array $headers = []): Response
    {
        return $this->send('POST', $uri, $payload, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    protected function get(string $uri, array $headers = []): Response
    {
        return $this->send('GET', $uri, null, $headers);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @param array<string, string>     $headers
     */
    protected function send(string $method, string $uri, ?array $payload = null, array $headers = []): Response
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $this->client->request(
            $method,
            $uri,
            server: $server,
            content: null === $payload ? null : json_encode($payload, \JSON_THROW_ON_ERROR),
        );

        return $this->client->getResponse();
    }

    /**
     * @return array<mixed>
     */
    protected static function decode(Response $response): array
    {
        $content = $response->getContent();
        self::assertIsString($content);

        $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, 'La réponse n\'est pas un objet JSON : '.$content);

        return $decoded;
    }

    private function truncateEverything(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        $tables = array_filter(
            $connection->createSchemaManager()->listTableNames(),
            static fn (string $table): bool => 'doctrine_migration_versions' !== $table,
        );

        if ([] === $tables) {
            return;
        }

        $connection->executeStatement(\sprintf('TRUNCATE %s RESTART IDENTITY CASCADE', implode(', ', $tables)));
    }
}
