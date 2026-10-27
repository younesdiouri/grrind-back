<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Shared\Domain\Avatar;
use App\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class AvatarTest extends ApiTestCase
{
    public function testCatalogAndExistingAccountShareTheDefault(): void
    {
        $player = $this->openAccount();
        $catalog = self::decode($this->get('/api/avatar-catalog', $player->headers));
        self::assertSame(1, $catalog['version']);
        self::assertSame(Avatar::DEFAULT, $catalog['default']);
        self::assertSame(Avatar::DEFAULT, self::decode($this->get('/api/me', $player->headers))['avatar']);
        self::assertSame(['slot' => 'HEAD', 'options' => ['BANDEAU_ECLAIR']], $catalog['headwear']);
        self::assertSame(401, $this->get('/api/avatar-catalog')->getStatusCode());
        self::assertSame(401, $this->send('PATCH', '/api/me', ['avatar' => Avatar::DEFAULT])->getStatusCode());
    }

    public function testSavedAvatarSurvivesReloadAndDoesNotChangeAnotherAccountOrEquipment(): void
    {
        $player = $this->openAccount();
        $other = $this->openAccount('other@grrind.app');
        $avatar = array_replace(Avatar::DEFAULT, ['body' => 'female', 'faceStyle' => 'round', 'noseStyle' => 'button', 'hairStyle' => 'ponytail', 'skinTone' => 'deep', 'eyeColor' => 'green', 'hairColor' => 'black']);
        $before = self::decode($this->get('/api/inventory', $player->headers));
        $response = $this->send('PATCH', '/api/me', ['avatar' => $avatar], $player->headers);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame($avatar, self::decode($response)['avatar']);
        $this->send('PATCH', '/api/me', ['displayName' => 'Nouveau nom'], $player->headers);
        self::assertSame($avatar, self::decode($this->get('/api/me', $player->headers))['avatar']);
        self::assertSame($avatar, self::decode($this->get('/api/players/'.$player->id, $player->headers))['avatar']);
        self::assertSame(Avatar::DEFAULT, self::decode($this->get('/api/me', $other->headers))['avatar']);
        self::assertSame($before, self::decode($this->get('/api/inventory', $player->headers)));
        self::assertSame(404, $this->get('/api/players/'.$player->id, $other->headers)->getStatusCode());
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('invalidAvatars')]
    public function testInvalidAvatarIsRejectedWithoutChangingTheProfile(array $changes): void
    {
        $player = $this->openAccount();
        $response = $this->send('PATCH', '/api/me', ['displayName' => 'Ne doit pas changer', 'avatar' => array_replace(Avatar::DEFAULT, $changes)], $player->headers);
        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        $profile = self::decode($this->get('/api/me', $player->headers));
        self::assertSame('Bob', $profile['displayName']);
        self::assertSame(Avatar::DEFAULT, $profile['avatar']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidAvatars(): iterable
    {
        yield 'version' => [['version' => 2]];
        yield 'version type' => [['version' => '1']];
        yield 'body' => [['body' => 'unknown']];
        yield 'face' => [['faceStyle' => 'unknown']];
        yield 'nose' => [['noseStyle' => 'unknown']];
        yield 'hair' => [['hairStyle' => 'unknown']];
        yield 'skin' => [['skinTone' => null]];
        yield 'eye' => [['eyeColor' => ['blue']]];
        yield 'hair color' => [['hairColor' => 'red']];
        yield 'retired square' => [['faceStyle' => 'square']];
        yield 'female wide' => [['body' => 'female', 'hairStyle' => 'buns']];
        yield 'female parted' => [['body' => 'female', 'noseStyle' => 'original']];
        yield 'male heart' => [['faceStyle' => 'heart']];
        yield 'equipment' => [['headband' => true]];
    }

    public function testPartialAvatarIsRejected(): void
    {
        $player = $this->openAccount();
        self::assertSame(422, $this->send('PATCH', '/api/me', ['avatar' => ['body' => 'male']], $player->headers)->getStatusCode());
    }
}
