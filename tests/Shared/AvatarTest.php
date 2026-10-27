<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Shared\Domain\Avatar;
use PHPUnit\Framework\TestCase;

final class AvatarTest extends TestCase
{
    public function testMissingAndUnknownVersionsUseTheFrozenV1Default(): void
    {
        self::assertSame(Avatar::DEFAULT, Avatar::resolve(null));
        self::assertSame(Avatar::DEFAULT, Avatar::resolve(['version' => 999, 'body' => 'female']));
    }

    public function testRetiredIdentifiersFallBackPerFieldAndRespectTheBody(): void
    {
        $avatar = Avatar::resolve(['version' => 1, 'body' => 'female', 'faceStyle' => 'square', 'noseStyle' => 'wide', 'hairStyle' => 'retired', 'skinTone' => 'deep']);
        self::assertSame('female', $avatar['body']);
        self::assertSame('original', $avatar['faceStyle']);
        self::assertSame('original', $avatar['noseStyle']);
        self::assertSame('buns', $avatar['hairStyle']);
        self::assertSame('deep', $avatar['skinTone']);
    }

    public function testEachBodyKeepsItsOwnChoicesAndFallsBackToItsOwn(): void
    {
        $male = Avatar::resolve(array_replace(Avatar::DEFAULT, ['faceStyle' => 'heart', 'noseStyle' => 'button', 'hairStyle' => 'ponytail']));
        self::assertSame(['original', 'wide', 'parted'], [$male['faceStyle'], $male['noseStyle'], $male['hairStyle']]);
        $female = array_replace(Avatar::DEFAULT, ['body' => 'female', 'faceStyle' => 'heart', 'noseStyle' => 'button', 'hairStyle' => 'buzzed']);
        self::assertSame($female, Avatar::resolve($female));
        self::assertSame(['male'], Avatar::bodies('hairStyle', 'mohawk'));
        self::assertSame(['female'], Avatar::bodies('hairStyle', 'bob'));
        self::assertSame(['male', 'female'], Avatar::bodies('hairStyle', 'buzzed'));
    }
}
