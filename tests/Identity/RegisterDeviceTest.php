<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Tests\Support\ApiTestCase;
use Symfony\Component\HttpFoundation\Response;

final class RegisterDeviceTest extends ApiTestCase
{
    private const array VALID = [
        'pushToken' => 'ExponentPushToken[abc123]',
        'platform' => 'IOS',
        'environment' => 'PRODUCTION',
    ];

    public function testRegistersANewDevice(): void
    {
        $bob = $this->openAccount();

        $response = $this->post('/api/devices', self::VALID, $bob->headers);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $device = self::decode($response);

        self::assertSame('IOS', $device['platform']);
        self::assertSame('PRODUCTION', $device['environment']);
        self::assertIsString($device['id']);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $device['id']);
        self::assertArrayNotHasKey('pushToken', $device, 'Le jeton ne doit jamais revenir dans une réponse.');
    }

    public function testReregisteringTheSameTokenDoesNotCreateASecondDevice(): void
    {
        $bob = $this->openAccount();

        $first = self::decode($this->post('/api/devices', self::VALID, $bob->headers));
        $second = self::decode($this->post('/api/devices', self::VALID, $bob->headers));

        self::assertSame($first['id'], $second['id']);
    }

    public function testReregisteringRefreshesTheEnvironmentAndPlatform(): void
    {
        $bob = $this->openAccount();

        $this->post('/api/devices', self::VALID, $bob->headers);
        $response = $this->post('/api/devices', [...self::VALID, 'environment' => 'DEVELOPMENT'], $bob->headers);

        self::assertSame('DEVELOPMENT', self::decode($response)['environment']);
    }

    /**
     * Le piège du ticket : le jeton appartient à l'appareil, pas au compte. Un même
     * téléphone qui change de compte doit voir la ligne changer de propriétaire, jamais
     * se dupliquer — sinon l'ancien compte continue de recevoir les notifications d'un
     * appareil qui ne lui appartient plus.
     */
    public function testATokenReusedByAnotherAccountChangesOwnerInstedOfDuplicating(): void
    {
        $bob = $this->openAccount('bob@grrind.app', 'Bob');
        $alice = $this->openAccount('alice@grrind.app', 'Alice');

        $bobsDevice = self::decode($this->post('/api/devices', self::VALID, $bob->headers));
        $alicesDevice = self::decode($this->post('/api/devices', self::VALID, $alice->headers));

        // Même jeton, donc même ligne : l'id ne change pas, seul le propriétaire change.
        self::assertSame($bobsDevice['id'], $alicesDevice['id']);
    }

    public function testRefusesAnAnonymousCall(): void
    {
        $response = $this->post('/api/devices', self::VALID);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedPayloads')]
    public function testRejectsMalformedInput(array $payload, string $expectedField): void
    {
        $bob = $this->openAccount();

        $response = $this->post('/api/devices', $payload, $bob->headers);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());

        $body = self::decode($response);
        self::assertIsArray($body['violations']);
        self::assertContains($expectedField, array_column($body['violations'], 'field'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function rejectedPayloads(): iterable
    {
        yield 'jeton absent' => [[...self::VALID, 'pushToken' => ''], 'pushToken'];
        yield 'plateforme absente' => [[...self::VALID, 'platform' => null], 'platform'];
        yield 'plateforme inconnue' => [[...self::VALID, 'platform' => 'WEB'], 'platform'];
        yield 'environnement absent' => [[...self::VALID, 'environment' => null], 'environment'];
        yield 'environnement inconnu' => [[...self::VALID, 'environment' => 'STAGING'], 'environment'];
    }
}
