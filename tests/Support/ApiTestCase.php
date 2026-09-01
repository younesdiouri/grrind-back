<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Base des tests d'API. Chaque test part d'une base vide : les tables sont
 * découvertes dans le schéma plutôt qu'énumérées, pour qu'un nouveau module
 * n'oblige pas à revenir ici.
 *
 * Et d'un joueur sans aucun modificateur actif : {@see ProgrammableModifiers} porte un état
 * statique, que la suite entière partagerait sinon.
 */
abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->truncateEverything();
        ProgrammableModifiers::grantNothing();
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
     * Ouvre un compte et rend de quoi écrire en son nom. Passe par la vraie route
     * d'inscription : un test d'écriture doit partir d'un jeton que le firewall
     * accepte pour de bon, pas d'un utilisateur posé dans le conteneur.
     */
    protected function openAccount(string $email = 'bob@grrind.app', string $displayName = 'Bob'): Account
    {
        $response = $this->post('/api/auth/register', [
            'email' => $email,
            'password' => 'un-mot-de-passe-assez-long',
            'displayName' => $displayName,
            'timezone' => 'Europe/Paris',
        ]);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);
        self::assertIsArray($body['user']);
        self::assertIsArray($body['tokens']);
        self::assertIsString($body['user']['id']);
        self::assertIsString($body['tokens']['accessToken']);

        return new Account(
            Uuid::fromString($body['user']['id']),
            ['Authorization' => 'Bearer '.$body['tokens']['accessToken']],
        );
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
            // Les rulesets sont des données de référence migrées, pas des faits du scénario.
            // Les vider rendrait chaque API test incapable d'exercer le runtime DB qu'il vise.
            static fn (string $table): bool => !\in_array($table, ['doctrine_migration_versions', 'game_enemy', 'game_item', 'game_loot_table', 'game_ruleset', 'game_settings', 'game_title'], true),
        );

        if ([] === $tables) {
            return;
        }

        $connection->executeStatement(\sprintf('TRUNCATE %s RESTART IDENTITY CASCADE', implode(', ', $tables)));
    }
}
