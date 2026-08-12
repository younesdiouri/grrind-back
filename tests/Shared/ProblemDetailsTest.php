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
     * Les deux pannes que `#[MapRequestPayload]` produit avant d'atteindre le contrôleur. Elles
     * sont testées ici parce qu'`OpenApiContractTest` déclare leurs statuts pour en dériver les
     * `type` du contrat : sans ces deux cas, cette déclaration serait une supposition.
     */
    public function testAMalformedBodyIsABadRequest(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/auth/register', server: ['CONTENT_TYPE' => 'application/json'], content: '{pas du json');

        $response = $client->getResponse();

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('https://grrind.app/problems/bad-request', self::decode($response)['type']);
    }

    public function testANonJsonBodyIsAnUnsupportedMediaType(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/auth/register', server: ['CONTENT_TYPE' => 'text/plain'], content: 'coucou');

        $response = $client->getResponse();

        self::assertSame(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, $response->getStatusCode());
        self::assertSame('https://grrind.app/problems/unsupported-media-type', self::decode($response)['type']);
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
