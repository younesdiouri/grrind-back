<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Admin\Domain\GameArtworkPack;
use App\Shared\Domain\Appearance;
use App\Tests\Support\ApiTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;

final class AppearanceTest extends ApiTestCase
{
    public function testEveryAccountCanChooseEachPublishedSportsAvatar(): void
    {
        $headers = $this->openAccount()->headers;
        self::assertSame(Appearance::DEFAULT, self::decode($this->get('/api/me', $headers))['appearance']);
        foreach (['STRENGTH_V1', 'RUNNER_V1', 'CLIMBER_V1', 'SWIMMER_V1'] as $key) {
            $response = $this->send('PATCH', '/api/me', ['appearance' => $key], $headers);
            self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
            self::assertSame($key, self::decode($response)['appearance']);
            self::assertSame($key, self::decode($this->get('/api/me', $headers))['appearance']);
        }
    }

    public function testUnknownAndUnavailableModelsCannotBeSelected(): void
    {
        $headers = $this->openAccount()->headers;
        self::assertSame(422, $this->send('PATCH', '/api/me', ['appearance' => 'GOLDEN_KNIGHT'], $headers)->getStatusCode());
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement("UPDATE game_artwork_pack SET active = FALSE WHERE pack_key = 'RUNNER_V1'");
        try {
            self::assertSame(422, $this->send('PATCH', '/api/me', ['appearance' => 'RUNNER_V1'], $headers)->getStatusCode());
            $catalog = self::decode($this->get('/api/appearances', $headers));
            self::assertIsArray($catalog['appearances']);
            self::assertContains('RUNNER_V1', array_column($catalog['appearances'], 'key'));
        } finally {
            $connection->executeStatement("UPDATE game_artwork_pack SET active = TRUE WHERE pack_key = 'RUNNER_V1'");
        }
    }

    public function testEveryPoseThumbnailAndWearableHasARealFileAndCompatibleAnchors(): void
    {
        $response = $this->get('/api/appearances', $this->openAccount()->headers);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $catalog = self::decode($response);
        self::assertIsArray($catalog['appearances']);
        self::assertIsArray($catalog['equipment']);
        self::assertCount(4, $catalog['appearances']);
        self::assertCount(8, $catalog['equipment']);
        $models = [];
        foreach ($catalog['appearances'] as $model) {
            self::assertIsArray($model);
            self::assertIsString($model['key']);
            $models[$model['key']] = $model;
            foreach (['imageUrls' => 512, 'thumbnailUrls' => 256, 'foregroundUrls' => 256] as $field => $size) {
                self::assertIsArray($model[$field]);
                foreach (['back', 'front'] as $view) {
                    $urls = $model[$field][$view];
                    self::assertIsArray($urls);
                    self::assertSame(GameArtworkPack::POSES, array_keys($urls));
                    foreach ($urls as $url) {
                        self::assertIsString($url);
                        $file = self::fileFor($url);
                        $dimensions = getimagesize($file);
                        self::assertNotFalse($dimensions);
                        self::assertSame([$size, $size], [$dimensions[0], $dimensions[1]], $file);
                    }
                }
            }
        }
        foreach ($catalog['equipment'] as $item) {
            self::assertIsArray($item);
            self::assertIsString($item['iconUrl']);
            self::fileFor($item['iconUrl']);
            self::assertIsArray($item['layers']);
            self::assertIsArray($item['compatibleAppearances']);
            foreach ($item['layers'] as $layer) {
                self::assertIsArray($layer);
                self::assertIsArray($layer['sourceUrls']);
                foreach (['back', 'front'] as $view) {
                    self::assertIsString($layer['sourceUrls'][$view]);
                    self::fileFor($layer['sourceUrls'][$view]);
                    foreach ($item['compatibleAppearances'] as $key) {
                        self::assertIsString($key);
                        $anchors = $models[$key]['attachments'];
                        self::assertIsArray($anchors);
                        self::assertIsArray($anchors[$view]);
                        self::assertIsString($layer['attachment']);
                        $series = $anchors[$view][$layer['attachment']];
                        self::assertIsArray($series);
                        self::assertSame(GameArtworkPack::POSES, array_keys($series));
                    }
                }
            }
        }
    }

    private static function fileFor(string $url): string
    {
        self::assertMatchesRegularExpression('#^https?://[^/]+/artwork/#', $url);
        $file = \dirname(__DIR__, 2).'/public'.parse_url($url, \PHP_URL_PATH);
        self::assertFileExists($file);

        return $file;
    }
}
