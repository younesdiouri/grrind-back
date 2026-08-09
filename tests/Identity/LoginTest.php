<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Tests\Support\ApiTestCase;
use Symfony\Component\HttpFoundation\Response;

final class LoginTest extends ApiTestCase
{
    private const string EMAIL = 'bob@grrind.app';
    private const string PASSWORD = 'un-mot-de-passe-assez-long';

    public function testReturnsATokenPair(): void
    {
        $this->registerBob();

        $response = $this->post('/api/auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $body = self::decode($response);

        self::assertIsArray($body['tokens']);
        self::assertNotEmpty($body['tokens']['accessToken']);
        self::assertNotEmpty($body['tokens']['refreshToken']);
        self::assertSame('Bearer', $body['tokens']['tokenType']);
        self::assertSame(900, $body['tokens']['expiresIn']);

        self::assertIsArray($body['user']);
        self::assertSame(self::EMAIL, $body['user']['email']);
    }

    public function testTheAccessTokenCarriesTheUserIdInSub(): void
    {
        $user = self::decode($this->registerBob())['user'];
        self::assertIsArray($user);
        $userId = $user['id'];

        $tokens = self::decode($this->post('/api/auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD]))['tokens'];
        self::assertIsArray($tokens);
        self::assertIsString($tokens['accessToken']);

        self::assertSame($userId, self::claims($tokens['accessToken'])['sub']);
    }

    public function testEachLoginOpensADistinctSession(): void
    {
        $this->registerBob();

        $first = self::decode($this->post('/api/auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD]));
        $second = self::decode($this->post('/api/auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD]));

        self::assertIsArray($first['tokens']);
        self::assertIsArray($second['tokens']);

        // Deux appareils, deux familles : se déconnecter de l'un ne doit pas
        // déconnecter l'autre.
        self::assertNotSame($first['tokens']['refreshToken'], $second['tokens']['refreshToken']);
    }

    public function testRejectsAWrongPassword(): void
    {
        $this->registerBob();

        $response = $this->post('/api/auth/login', ['email' => self::EMAIL, 'password' => 'ce-n-est-pas-le-bon']);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('https://grrind.app/problems/invalid-credentials', self::decode($response)['type']);
    }

    public function testAnswersTheSameThingForAnUnknownAccount(): void
    {
        $this->registerBob();

        $unknown = $this->post('/api/auth/login', ['email' => 'personne@grrind.app', 'password' => self::PASSWORD]);
        $wrongPassword = $this->post('/api/auth/login', ['email' => self::EMAIL, 'password' => 'ce-n-est-pas-le-bon']);

        // Distinguer les deux ferait du login un oracle d'existence de comptes.
        self::assertSame($unknown->getStatusCode(), $wrongPassword->getStatusCode());
        self::assertSame(self::decode($unknown), self::decode($wrongPassword));
    }

    public function testLogsInWhateverTheSpellingOfTheAddress(): void
    {
        $this->registerBob();

        $response = $this->post('/api/auth/login', ['email' => '  BOB@Grrind.app ', 'password' => self::PASSWORD]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testRegistrationAlreadyOpensTheSession(): void
    {
        $body = self::decode($this->registerBob());

        self::assertIsArray($body['tokens']);
        self::assertNotEmpty($body['tokens']['accessToken']);
    }

    private function registerBob(): Response
    {
        return $this->post('/api/auth/register', [
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
            'displayName' => 'Bob',
            'timezone' => 'Europe/Paris',
        ]);
    }

    /**
     * @return array<mixed>
     */
    private static function claims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        self::assertCount(3, $parts, 'Un JWT compte trois segments.');

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        self::assertIsString($payload);

        $claims = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($claims);

        return $claims;
    }
}
