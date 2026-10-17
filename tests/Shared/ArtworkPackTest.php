<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Admin\Domain\GameArtworkPack;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class ArtworkPackTest extends TestCase
{
    public function testRejectsAnAvatarWithoutAllCombatPoses(): void
    {
        $pack = new GameArtworkPack();
        $pack->setKey('RUNNER_V1');
        $pack->setName('Course');
        $pack->setManifestJson('{"pixelArt":true,"imageUrls":{"back":{"idle":"/artwork/runner/v1/idle.png"}}}');

        self::assertGreaterThan(0, Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()->validate($pack)->count());
    }

    public function testAcceptsAnIndependentEquipmentLayerAndRejectsUnsafePathsAndTransforms(): void
    {
        $pack = new GameArtworkPack();
        $pack->setKey('PURSUIT_GOGGLES');
        $pack->setName('Lunettes de course-poursuite');
        $pack->setKind('EQUIPMENT');
        $manifest = [
            'slot' => 'HEAD', 'iconUrl' => '/artwork/items/goggles/v1/icon.png',
            'compatibleAppearances' => ['RUNNER_V1'],
            'layers' => [[
                'attachment' => 'eyes', 'plane' => 'front', 'aspectRatio' => 2,
                'pivot' => ['x' => 0.5, 'y' => 0.5], 'scale' => 1,
                'sourceUrls' => ['back' => '/artwork/items/goggles/v1/back.png', 'front' => '/artwork/items/goggles/v1/front.png'],
            ]],
        ];
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $pack->setManifestJson(json_encode($manifest, \JSON_THROW_ON_ERROR));
        self::assertCount(0, $validator->validate($pack));

        $manifest['layers'][0]['scale'] = -1;
        $pack->setManifestJson(json_encode($manifest, \JSON_THROW_ON_ERROR));
        self::assertGreaterThan(0, $validator->validate($pack)->count());

        $manifest['layers'][0]['scale'] = 1;
        $manifest['layers'][0]['sourceUrls']['back'] = '/artwork/../../private.png';
        $pack->setManifestJson(json_encode($manifest, \JSON_THROW_ON_ERROR));
        self::assertGreaterThan(0, $validator->validate($pack)->count());
    }
}
