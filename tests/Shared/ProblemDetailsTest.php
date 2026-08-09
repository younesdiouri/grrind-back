<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ProblemDetailsTest extends WebTestCase
{
    public function testUnknownRouteAnswersProblemJson(): void
    {
        $client = self::createClient();
        $client->request('GET', '/route-qui-nexiste-pas');

        $response = $client->getResponse();

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));

        $problem = self::decode($response);

        self::assertSame('https://grrind.app/problems/not-found', $problem['type']);
        self::assertSame('Not Found', $problem['title']);
        self::assertSame(404, $problem['status']);
    }

    public function testMethodNotAllowedKeepsTheAllowHeader(): void
    {
        $client = self::createClient();
        $client->request('POST', '/health');

        $response = $client->getResponse();

        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $response->getStatusCode());
        self::assertSame('GET', $response->headers->get('Allow'));
        self::assertSame(405, self::decode($response)['status']);
    }

    /**
     * @return array<mixed>
     */
    private static function decode(Response $response): array
    {
        $content = $response->getContent();
        self::assertIsString($content);

        $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
