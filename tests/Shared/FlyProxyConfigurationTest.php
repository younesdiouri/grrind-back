<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/** La confiance au proxy est une frontière de sécurité, pas une correction d'URL locale. */
final class FlyProxyConfigurationTest extends TestCase
{
    public function testFlyTrustsItsImmediateProxyAndOnlyTheForwardedHeadersItUses(): void
    {
        $fly = file_get_contents(\dirname(__DIR__, 2).'/fly.toml');
        self::assertIsString($fly);
        self::assertStringContainsString("TRUSTED_PROXIES = '127.0.0.1,REMOTE_ADDR'", $fly);

        $configuration = Yaml::parseFile(\dirname(__DIR__, 2).'/config/packages/framework.yaml');
        self::assertIsArray($configuration);
        $framework = $configuration['framework'] ?? null;
        self::assertIsArray($framework);
        self::assertSame(['x-forwarded-for', 'x-forwarded-proto'], $framework['trusted_headers'] ?? null);
    }
}
