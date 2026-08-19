<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Shared\Application\PushTargets;
use App\Tests\Support\ApiTestCase;

/**
 * Le câblage du port {@see PushTargets} : c'est le seul test qui le prouve, comme
 * `DailyLoadTest` est le seul à prouver celui de `PlayerTimezones`.
 *
 * Ce qui est vérifié ici précisément : filtrer par environnement est fait une fois, dans
 * l'implémentation, et non laissé à la charge d'un futur consommateur — voir le docblock
 * du port pour le pourquoi.
 */
final class PushTargetsTest extends ApiTestCase
{
    public function testOnlyRendersTokensOfTheRunningEnvironment(): void
    {
        $bob = $this->openAccount();

        $this->post('/api/devices', [
            'pushToken' => 'dev-token',
            'platform' => 'IOS',
            'environment' => 'DEVELOPMENT',
        ], $bob->headers);

        $this->post('/api/devices', [
            'pushToken' => 'prod-token',
            'platform' => 'IOS',
            'environment' => 'PRODUCTION',
        ], $bob->headers);

        $targets = self::getContainer()->get(PushTargets::class);
        self::assertInstanceOf(PushTargets::class, $targets);

        // La suite de tests tourne en environnement `test`, jamais `prod` :
        // `DeviceEnvironment::ofRuntimeEnvironment()` le range du côté `DEVELOPMENT`, donc
        // seul `dev-token` doit revenir — jamais les deux.
        self::assertSame(['dev-token'], $targets->of($bob->id));
    }

    public function testRendersNothingForAPlayerWithNoDevice(): void
    {
        $bob = $this->openAccount();

        $targets = self::getContainer()->get(PushTargets::class);
        self::assertInstanceOf(PushTargets::class, $targets);

        self::assertSame([], $targets->of($bob->id));
    }
}
