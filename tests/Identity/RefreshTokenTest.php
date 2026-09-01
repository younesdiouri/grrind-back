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

    /**
     * #250 : présenter un jeton déjà consommé n'est plus, à lui seul, un rejeu — voir le
     * docblock de `RefreshSessionHandler`. Si son successeur direct n'a jamais servi, c'est
     * la signature d'une réponse de rotation que le client n'a jamais reçue (mesuré en
     * production : 49 minutes entre le `COMMIT` serveur et cette re-présentation), pas
     * d'une copie qui circule. La famille survit et une paire neuve est émise.
     */
    public function testARotationLostInFlightIsRecoveredNotBurnt(): void
    {
        $first = $this->openSession();

        // Le client rotate, mais on ne se sert jamais du successeur ensuite — exactement
        // comme un client qui n'aurait jamais reçu la réponse.
        $lost = self::decode($this->post('/api/auth/refresh', ['refreshToken' => $first]))['tokens'];
        self::assertIsArray($lost);
        self::assertIsString($lost['refreshToken']);

        $recovered = $this->post('/api/auth/refresh', ['refreshToken' => $first]);

        self::assertSame(Response::HTTP_OK, $recovered->getStatusCode());
        $recoveredTokens = self::decode($recovered)['tokens'];
        self::assertIsArray($recoveredTokens);
        self::assertIsString($recoveredTokens['refreshToken']);
        self::assertNotSame($first, $recoveredTokens['refreshToken']);
        self::assertNotSame(
            $lost['refreshToken'],
            $recoveredTokens['refreshToken'],
            'Un rejeu toléré ne rend jamais un secret déjà émis : il rotate de nouveau.',
        );

        // Le jeton rendu au client dans le cas légitime est utilisable.
        self::assertSame(
            Response::HTTP_OK,
            $this->post('/api/auth/refresh', ['refreshToken' => $recoveredTokens['refreshToken']])->getStatusCode(),
        );
    }

    /**
     * #250 : le cas que la récupération ne couvre pas. Si le successeur direct a déjà
     * servi à une rotation suivante, la présentation de l'ancien reste un vrai rejeu — on
     * ne peut plus distinguer le voleur du vrai client, donc la lignée entière saute,
     * comme avant #250.
     */
    public function testReplayingAnOldTokenKillsTheWholeFamilyWhenItsSuccessorAlreadyServed(): void
    {
        $first = $this->openSession();

        $second = self::decode($this->post('/api/auth/refresh', ['refreshToken' => $first]))['tokens'];
        self::assertIsArray($second);
        self::assertIsString($second['refreshToken']);

        $third = self::decode($this->post('/api/auth/refresh', ['refreshToken' => $second['refreshToken']]))['tokens'];
        self::assertIsArray($third);
        self::assertIsString($third['refreshToken']);

        // Le successeur de $first (= $second) a déjà servi à produire $third : plus rien
        // ne distingue cette présentation d'un vol.
        $response = $this->post('/api/auth/refresh', ['refreshToken' => $first]);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('https://grrind.app/problems/invalid-refresh-token', self::decode($response)['type']);

        // La famille entière tombe, y compris le jeton légitime le plus récent.
        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $this->post('/api/auth/refresh', ['refreshToken' => $third['refreshToken']])->getStatusCode(),
        );
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
