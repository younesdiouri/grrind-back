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

        $user = self::decode($response)['user'];
        self::assertIsArray($user);

        self::assertSame('bob@grrind.app', $user['email']);
        self::assertSame('Bob', $user['displayName']);
        self::assertSame('Europe/Paris', $user['timezone']);
        self::assertSame('en', $user['locale']);
        self::assertIsString($user['id']);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $user['id']);
        self::assertArrayNotHasKey('password', $user);
        self::assertArrayNotHasKey('passwordHash', $user);
    }

    public function testNormalisesTheEmailBeforeStoringIt(): void
    {
        $response = $this->post('/api/auth/register', [...self::VALID, 'email' => '  Bob@GRRIND.app ']);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $user = self::decode($response)['user'];
        self::assertIsArray($user);
        self::assertSame('bob@grrind.app', $user['email']);
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
        yield 'locale inconnue' => [[...self::VALID, 'locale' => 'es'], 'locale'];
    }

    public function testRegistrationIsReachableWithoutAuthentication(): void
    {
        // Le firewall ^/api est stateless : sans la règle PUBLIC_ACCESS sur
        // ^/api/auth, personne ne pourrait jamais créer de compte.
        self::assertSame(Response::HTTP_CREATED, $this->post('/api/auth/register', self::VALID)->getStatusCode());
    }

    public function testStoresTheRequestedLocaleOrNegotiatesTheRequestLanguage(): void
    {
        $explicit = self::decode($this->post('/api/auth/register', [...self::VALID, 'locale' => 'fr']));
        self::assertIsArray($explicit['user']);
        self::assertSame('fr', $explicit['user']['locale']);

        $negotiated = self::decode($this->post(
            '/api/auth/register',
            [...self::VALID, 'email' => 'alice@grrind.app'],
            ['Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.8'],
        ));
        self::assertIsArray($negotiated['user']);
        self::assertSame('fr', $negotiated['user']['locale']);
    }
}
