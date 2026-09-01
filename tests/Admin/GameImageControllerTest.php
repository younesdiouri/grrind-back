<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class GameImageControllerTest extends WebTestCase
{
    public function testPublicPlaceholderIsReachableWithoutAdminSession(): void
    {
        $client = self::createClient();
        $client->request('GET', '/game-images/placeholder.png');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertResponseHeaderSame('content-type', 'image/png');
    }

    public function testTraversalAndUnknownNamesAreRejected(): void
    {
        $client = self::createClient();
        $client->request('GET', '/game-images/../../.env');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }
}
