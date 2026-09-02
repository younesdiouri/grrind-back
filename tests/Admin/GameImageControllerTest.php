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

    public function testPublishedContentHashIsPublicAndImmutable(): void
    {
        $client = self::createClient();
        $directory = self::getContainer()->getParameter('kernel.project_dir').'/var/game-images';
        self::assertIsString($directory);
        if (!is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }
        $name = sha1('image-http-test').'.png';
        $path = $directory.\DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL8ywAAAABJRU5ErkJggg==', true));

        try {
            $client->request('GET', '/game-images/'.$name);

            self::assertResponseStatusCodeSame(Response::HTTP_OK);
            self::assertResponseHeaderSame('content-type', 'image/png');
            $cacheControl = $client->getResponse()->headers->get('cache-control');
            self::assertIsString($cacheControl);
            self::assertStringContainsString('max-age=31536000', $cacheControl);
            self::assertStringContainsString('immutable', $cacheControl);
        } finally {
            unlink($path);
        }
    }
}
