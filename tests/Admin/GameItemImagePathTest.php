<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Domain\GameItem;
use PHPUnit\Framework\TestCase;

final class GameItemImagePathTest extends TestCase
{
    public function testEditingWithoutUploadKeepsPublishedImage(): void
    {
        $item = new GameItem();
        $item->setImagePath('0123456789012345678901234567890123456789.png');
        $item->setImagePath('');

        self::assertSame('0123456789012345678901234567890123456789.png', $item->getImagePath());
    }
}
