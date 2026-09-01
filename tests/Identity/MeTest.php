<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Tests\Support\ApiTestCase;
use Symfony\Component\HttpFoundation\Response;

final class MeTest extends ApiTestCase
{
    private const string EMAIL = 'bob@grrind.app';
    private const string PASSWORD = 'un-mot-de-passe-assez-long';

    public function testReturnsTheProfileOfTheBearer(): void
    {
        $response = $this->get('/api/me', $this->authenticated());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $body = self::decode($response);

        self::assertSame(self::EMAIL, $body['email']);
        self::assertSame('Bob', $body['displayName']);
        self::assertSame('Europe/Paris', $body['timezone']);
        // Toutes activées à l'inscription (#132), et la liste grandit avec le catalogue :
        // `UserResource` itère sur `NotificationCategory`, donc une catégorie ajoutée
        // apparaît ici sans qu'on y touche — et c'est ce test qui le rappelle.
        self::assertSame(
            ['GUILD_ACTIVITY' => true, 'RISALA_TURN' => true, 'RISALA_REVEALED' => true, 'SESSION_CREDITED' => true],
            $body['notificationPreferences'],
            'Le défaut à l\'inscription est activé (#132).',
        );
    }

    public function testRefusesAnAnonymousCall(): void
    {
        $response = $this->get('/api/me');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame('https://grrind.app/problems/access-token-missing', self::decode($response)['type']);
    }

    public function testRefusesAForgedToken(): void
    {
        $response = $this->get('/api/me', ['Authorization' => 'Bearer pas.un.jwt']);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('https://grrind.app/problems/access-token-invalid', self::decode($response)['type']);
    }

    public function testRefusesARefreshTokenUsedAsAnAccessToken(): void
    {
        $session = $this->openSession();
        self::assertIsString($session['refreshToken']);

        $response = $this->get('/api/me', ['Authorization' => 'Bearer '.$session['refreshToken']]);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testUpdatesOnlyWhatIsSent(): void
    {
        $headers = $this->authenticated();

        $response = $this->send('PATCH', '/api/me', ['timezone' => 'Asia/Tokyo'], $headers);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $body = self::decode($response);

        self::assertSame('Asia/Tokyo', $body['timezone']);
        self::assertSame('Bob', $body['displayName'], 'Un champ absent du PATCH ne doit pas être écrasé.');
    }

    public function testTheChangeSurvivesTheRequest(): void
    {
        $headers = $this->authenticated();

        $this->send('PATCH', '/api/me', ['displayName' => 'Bobby'], $headers);

        self::assertSame('Bobby', self::decode($this->get('/api/me', $headers))['displayName']);
    }

    public function testRejectsAnImpossibleTimezone(): void
    {
        $response = $this->send('PATCH', '/api/me', ['timezone' => 'Europe/Atlantis'], $this->authenticated());

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function testRejectsAnEmptyDisplayName(): void
    {
        $response = $this->send('PATCH', '/api/me', ['displayName' => '   '], $this->authenticated());

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function testCuttingACategoryTurnsItOffWithoutTouchingTheOthers(): void
    {
        $headers = $this->authenticated();

        $response = $this->send('PATCH', '/api/me', [
            'notificationPreferences' => [
                ['category' => 'GUILD_ACTIVITY', 'enabled' => false],
            ],
        ], $headers);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(
            ['GUILD_ACTIVITY' => false, 'RISALA_TURN' => true, 'RISALA_REVEALED' => true, 'SESSION_CREDITED' => true],
            self::decode($response)['notificationPreferences'],
        );
    }

    public function testACategoryAbsentFromThePatchStaysUntouched(): void
    {
        $headers = $this->authenticated();

        $this->send('PATCH', '/api/me', [
            'notificationPreferences' => [
                ['category' => 'GUILD_ACTIVITY', 'enabled' => false],
            ],
        ], $headers);

        // Un PATCH qui ne parle pas des préférences ne doit rien y remettre à zéro —
        // même garantie que `displayName`/`timezone`.
        $this->send('PATCH', '/api/me', ['displayName' => 'Bobby'], $headers);

        self::assertSame(
            ['GUILD_ACTIVITY' => false, 'RISALA_TURN' => true, 'RISALA_REVEALED' => true, 'SESSION_CREDITED' => true],
            self::decode($this->get('/api/me', $headers))['notificationPreferences'],
        );
    }

    public function testThePreferenceSurvivesTheRequest(): void
    {
        $headers = $this->authenticated();

        $this->send('PATCH', '/api/me', [
            'notificationPreferences' => [
                ['category' => 'GUILD_ACTIVITY', 'enabled' => false],
            ],
        ], $headers);

        self::assertSame(
            ['GUILD_ACTIVITY' => false, 'RISALA_TURN' => true, 'RISALA_REVEALED' => true, 'SESSION_CREDITED' => true],
            self::decode($this->get('/api/me', $headers))['notificationPreferences'],
        );
    }

    public function testRejectsAnUnknownCategory(): void
    {
        $response = $this->send('PATCH', '/api/me', [
            'notificationPreferences' => [
                ['category' => 'NOT_A_CATEGORY', 'enabled' => false],
            ],
        ], $this->authenticated());

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    /**
     * @return array<string, string>
     */
    private function authenticated(): array
    {
        $session = $this->openSession();
        self::assertIsString($session['accessToken']);

        return ['Authorization' => 'Bearer '.$session['accessToken']];
    }

    /**
     * @return array<mixed>
     */
    private function openSession(): array
    {
        $response = $this->post('/api/auth/register', [
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
            'displayName' => 'Bob',
            'timezone' => 'Europe/Paris',
        ]);

        $tokens = self::decode($response)['tokens'];
        self::assertIsArray($tokens);

        return $tokens;
    }
}
