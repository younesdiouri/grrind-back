<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Tests\Support\ApiTestCase;
use Symfony\Component\HttpFoundation\Response;

final class RegisterTest extends ApiTestCase
{
    private const array VALID = [
        'email' => 'bob@grrind.app',
        'password' => 'un-mot-de-passe-assez-long',
        'displayName' => 'Bob',
        'timezone' => 'Europe/Paris',
    ];

    public function testCreatesAnAccount(): void
    {
        $response = $this->post('/api/auth/register', self::VALID);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());

        $body = self::decode($response);

        self::assertSame('bob@grrind.app', $body['email']);
        self::assertSame('Bob', $body['displayName']);
        self::assertSame('Europe/Paris', $body['timezone']);
        self::assertIsString($body['id']);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $body['id']);
        self::assertArrayNotHasKey('password', $body);
        self::assertArrayNotHasKey('passwordHash', $body);
    }

    public function testNormalisesTheEmailBeforeStoringIt(): void
    {
        $response = $this->post('/api/auth/register', [...self::VALID, 'email' => '  Bob@GRRIND.app ']);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        self::assertSame('bob@grrind.app', self::decode($response)['email']);
    }

    public function testRefusesAnAddressAlreadyTakenEvenSpeltDifferently(): void
    {
        $this->post('/api/auth/register', self::VALID);
        $response = $this->post('/api/auth/register', [...self::VALID, 'email' => 'BOB@grrind.app']);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame('https://grrind.app/problems/email-already-used', self::decode($response)['type']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedPayloads')]
    public function testRejectsMalformedInput(array $payload, string $expectedField): void
    {
        $response = $this->post('/api/auth/register', $payload);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());

        $body = self::decode($response);

        self::assertSame('https://grrind.app/problems/validation-failed', $body['type']);
        self::assertIsArray($body['violations']);
        self::assertContains($expectedField, array_column($body['violations'], 'field'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function rejectedPayloads(): iterable
    {
        yield 'e-mail absent' => [[...self::VALID, 'email' => ''], 'email'];
        yield 'e-mail invalide' => [[...self::VALID, 'email' => 'pas-une-adresse'], 'email'];
        yield 'mot de passe trop court' => [[...self::VALID, 'password' => 'court'], 'password'];
        yield 'pseudo vide' => [[...self::VALID, 'displayName' => ''], 'displayName'];
        yield 'pseudo trop long' => [[...self::VALID, 'displayName' => str_repeat('a', 41)], 'displayName'];
        yield 'fuseau inconnu' => [[...self::VALID, 'timezone' => 'Europe/Atlantis'], 'timezone'];
    }

    public function testRegistrationIsReachableWithoutAuthentication(): void
    {
        // Le firewall ^/api est stateless : sans la règle PUBLIC_ACCESS sur
        // ^/api/auth, personne ne pourrait jamais créer de compte.
        self::assertSame(Response::HTTP_CREATED, $this->post('/api/auth/register', self::VALID)->getStatusCode());
    }
}
