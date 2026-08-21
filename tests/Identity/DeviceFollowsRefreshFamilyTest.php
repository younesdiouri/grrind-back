<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Shared\Application\PushTargets;
use App\Shared\Domain\NotificationCategory;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * #136 (arbitrage B) : la famille de refresh tokens est la clé de l'appareil. Le claim `fid`
 * du jeton d'accès porte cette famille du login jusqu'au dernier refresh, et se déconnecter —
 * ou se faire couper sur un rejeu détecté — retire le jeton de push qu'elle portait, sans que
 * le client ait à s'en souvenir.
 */
final class DeviceFollowsRefreshFamilyTest extends ApiTestCase
{
    private const string EMAIL = 'bob@grrind.app';
    private const string PASSWORD = 'un-mot-de-passe-assez-long';
    private const array DEVICE = [
        'pushToken' => 'ExponentPushToken[abc123]',
        'platform' => 'IOS',
        'environment' => 'PRODUCTION',
    ];

    public function testTheAccessTokenCarriesTheFamilyIdInFid(): void
    {
        $tokens = $this->register();
        self::assertIsString($tokens['accessToken']);

        $fid = self::claims($tokens['accessToken'])['fid'] ?? null;

        self::assertIsString($fid, 'Le jeton d\'accès doit porter la famille dont il est né.');
        self::assertTrue(Uuid::isValid($fid));
    }

    public function testTheFidStaysStableAcrossRotation(): void
    {
        $tokens = $this->register();
        self::assertIsString($tokens['accessToken']);
        self::assertIsString($tokens['refreshToken']);
        $originalFid = self::claims($tokens['accessToken'])['fid'];

        $refreshed = self::decode($this->post('/api/auth/refresh', ['refreshToken' => $tokens['refreshToken']]))['tokens'];
        self::assertIsArray($refreshed);
        self::assertIsString($refreshed['accessToken']);

        self::assertSame($originalFid, self::claims($refreshed['accessToken'])['fid'], 'Une rotation garde la même famille — même lignée, même appareil.');
    }

    public function testEachLoginCarriesADistinctFid(): void
    {
        $this->register();

        $first = self::decode($this->post('/api/auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD]))['tokens'];
        $second = self::decode($this->post('/api/auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD]))['tokens'];
        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertIsString($first['accessToken']);
        self::assertIsString($second['accessToken']);

        self::assertNotSame(
            self::claims($first['accessToken'])['fid'],
            self::claims($second['accessToken'])['fid'],
            'Deux appareils, deux familles.',
        );
    }

    public function testLoggingOutRemovesThePushTokenOfThatDevice(): void
    {
        $tokens = $this->register();
        self::assertIsString($tokens['accessToken']);
        self::assertIsString($tokens['refreshToken']);

        $this->registerDevice($tokens['accessToken']);
        $userId = $this->userIdOf($tokens['accessToken']);

        self::assertSame([self::DEVICE['pushToken']], $this->pushTargetsOf($userId));

        $this->post('/api/auth/logout', ['refreshToken' => $tokens['refreshToken']]);

        self::assertSame([], $this->pushTargetsOf($userId), 'Se déconnecter doit couper les notifications sans que le client se désenregistre.');
    }

    /**
     * Le pendant exact de `RefreshTokenTest::testLogoutOnlyDisconnectsItsOwnDevice` : se
     * déconnecter d'un appareil ne doit pas couper les notifications d'un autre.
     */
    public function testLoggingOutOnlyRemovesItsOwnDevicesPushToken(): void
    {
        $this->register();

        $phone = self::decode($this->post('/api/auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD]))['tokens'];
        $watch = self::decode($this->post('/api/auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD]))['tokens'];
        self::assertIsArray($phone);
        self::assertIsArray($watch);
        self::assertIsString($phone['accessToken']);
        self::assertIsString($phone['refreshToken']);
        self::assertIsString($watch['accessToken']);

        $this->registerDevice($phone['accessToken'], 'phone-token');
        $this->registerDevice($watch['accessToken'], 'watch-token');
        $userId = $this->userIdOf($phone['accessToken']);

        $this->post('/api/auth/logout', ['refreshToken' => $phone['refreshToken']]);

        self::assertSame(['watch-token'], $this->pushTargetsOf($userId));
    }

    /**
     * La moitié de la justification de l'arbitrage B : un rejeu détecté coupe la famille
     * *et* l'appareil qu'elle portait, exactement comme une déconnexion volontaire — sinon
     * un vol de session laisserait les notifications partir sur l'appareil compromis.
     */
    public function testAReplayDetectedOnRefreshRemovesThePushTokenToo(): void
    {
        $tokens = $this->register();
        self::assertIsString($tokens['accessToken']);
        self::assertIsString($tokens['refreshToken']);

        $this->registerDevice($tokens['accessToken']);
        $userId = $this->userIdOf($tokens['accessToken']);

        // Une rotation légitime, puis un rejeu de l'ancien jeton : le signal qu'une copie
        // circule.
        $this->post('/api/auth/refresh', ['refreshToken' => $tokens['refreshToken']]);
        $replay = $this->post('/api/auth/refresh', ['refreshToken' => $tokens['refreshToken']]);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $replay->getStatusCode());
        self::assertSame([], $this->pushTargetsOf($userId));
    }

    /**
     * Une réinscription du même appareil sur le même compte ouvre une famille neuve à
     * chaque login (#136) : `POST /api/devices` doit reprendre la ligne sous la famille de
     * la session courante, pas garder celle du login précédent.
     */
    public function testReregisteringAfterARelogUpdatesTheDeviceFamily(): void
    {
        $this->register();

        $first = self::decode($this->post('/api/auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD]))['tokens'];
        self::assertIsArray($first);
        self::assertIsString($first['accessToken']);
        $this->registerDevice($first['accessToken']);

        $second = self::decode($this->post('/api/auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD]))['tokens'];
        self::assertIsArray($second);
        self::assertIsString($second['accessToken']);
        self::assertIsString($second['refreshToken']);
        $this->registerDevice($second['accessToken']);

        $userId = $this->userIdOf($second['accessToken']);

        // Se déconnecter de la session la plus récente doit couper le jeton de push,
        // parce que `claim()` l'a raccroché à la famille de ce second login.
        $this->post('/api/auth/logout', ['refreshToken' => $second['refreshToken']]);

        self::assertSame([], $this->pushTargetsOf($userId));
    }

    /**
     * @return array<mixed>
     */
    private function register(): array
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

    private function registerDevice(string $accessToken, string $pushToken = self::DEVICE['pushToken']): void
    {
        $response = $this->post('/api/devices', [...self::DEVICE, 'pushToken' => $pushToken], [
            'Authorization' => 'Bearer '.$accessToken,
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    }

    private function userIdOf(string $accessToken): Uuid
    {
        $sub = self::claims($accessToken)['sub'] ?? null;
        self::assertIsString($sub);

        return Uuid::fromString($sub);
    }

    /**
     * @return list<string>
     */
    private function pushTargetsOf(Uuid $userId): array
    {
        $targets = self::getContainer()->get(PushTargets::class);
        self::assertInstanceOf(PushTargets::class, $targets);

        return $targets->of($userId, NotificationCategory::GuildActivity);
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
