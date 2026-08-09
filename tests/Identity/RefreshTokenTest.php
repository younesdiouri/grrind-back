<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Tests\Support\ApiTestCase;
use Symfony\Component\HttpFoundation\Response;

final class RefreshTokenTest extends ApiTestCase
{
    private const string EMAIL = 'bob@grrind.app';
    private const string PASSWORD = 'un-mot-de-passe-assez-long';

    public function testExchangesTheTokenForAFreshPair(): void
    {
        $refreshToken = $this->openSession();

        $response = $this->post('/api/auth/refresh', ['refreshToken' => $refreshToken]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $tokens = self::decode($response)['tokens'];
        self::assertIsArray($tokens);

        self::assertNotEmpty($tokens['accessToken']);
        self::assertNotSame($refreshToken, $tokens['refreshToken'], 'Le refresh token doit tourner à chaque échange.');
    }

    public function testTheOldTokenIsBurntOnceExchanged(): void
    {
        $first = $this->openSession();
        $this->post('/api/auth/refresh', ['refreshToken' => $first]);

        $response = $this->post('/api/auth/refresh', ['refreshToken' => $first]);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('https://grrind.app/problems/invalid-refresh-token', self::decode($response)['type']);
    }

    public function testReplayingAnOldTokenKillsTheWholeFamily(): void
    {
        $first = $this->openSession();

        $second = self::decode($this->post('/api/auth/refresh', ['refreshToken' => $first]))['tokens'];
        self::assertIsArray($second);
        self::assertIsString($second['refreshToken']);

        // Rejeu du jeton déjà consommé : on ne sait pas qui du client ou du voleur
        // le présente, donc la lignée entière saute.
        $this->post('/api/auth/refresh', ['refreshToken' => $first]);

        $response = $this->post('/api/auth/refresh', ['refreshToken' => $second['refreshToken']]);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testAnUnknownTokenIsRefused(): void
    {
        $this->openSession();

        $response = $this->post('/api/auth/refresh', ['refreshToken' => 'ce-jeton-n-a-jamais-existe']);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testLogoutRevokesTheSession(): void
    {
        $refreshToken = $this->openSession();

        $response = $this->post('/api/auth/logout', ['refreshToken' => $refreshToken]);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $this->post('/api/auth/refresh', ['refreshToken' => $refreshToken])->getStatusCode(),
        );
    }

    public function testLogoutIsIdempotentAndSaysNothingAboutTheToken(): void
    {
        $refreshToken = $this->openSession();

        $this->post('/api/auth/logout', ['refreshToken' => $refreshToken]);

        self::assertSame(Response::HTTP_NO_CONTENT, $this->post('/api/auth/logout', ['refreshToken' => $refreshToken])->getStatusCode());
        self::assertSame(Response::HTTP_NO_CONTENT, $this->post('/api/auth/logout', ['refreshToken' => 'jeton-inconnu'])->getStatusCode());
    }

    public function testLogoutOnlyDisconnectsItsOwnDevice(): void
    {
        $this->register();

        $phone = $this->logIn();
        $watch = $this->logIn();

        $this->post('/api/auth/logout', ['refreshToken' => $phone]);

        self::assertSame(Response::HTTP_OK, $this->post('/api/auth/refresh', ['refreshToken' => $watch])->getStatusCode());
    }

    private function openSession(): string
    {
        $this->register();

        return $this->logIn();
    }

    private function register(): void
    {
        $this->post('/api/auth/register', [
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
            'displayName' => 'Bob',
            'timezone' => 'Europe/Paris',
        ]);
    }

    private function logIn(): string
    {
        $tokens = self::decode($this->post('/api/auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD]))['tokens'];
        self::assertIsArray($tokens);
        self::assertIsString($tokens['refreshToken']);

        return $tokens['refreshToken'];
    }
}
