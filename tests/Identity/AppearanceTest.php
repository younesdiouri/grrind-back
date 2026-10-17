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
        foreach (['STRENGTH_V3', 'RUNNER_V3', 'CLIMBER_V3', 'SWIMMER_V3'] as $key) {
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
        $connection->executeStatement("UPDATE game_artwork_pack SET active = FALSE WHERE pack_key = 'RUNNER_V3'");
        try {
            self::assertSame(422, $this->send('PATCH', '/api/me', ['appearance' => 'RUNNER_V3'], $headers)->getStatusCode());
            $catalog = self::decode($this->get('/api/appearances', $headers));
            self::assertIsArray($catalog['appearances']);
            self::assertContains('RUNNER_V3', array_column($catalog['appearances'], 'key'));
        } finally {
            $connection->executeStatement("UPDATE game_artwork_pack SET active = TRUE WHERE pack_key = 'RUNNER_V3'");
        }
    }

    public function testEveryPoseThumbnailAndWearableHasARealFileAndCompatibleAnchors(): void
    {
        $response = $this->get('/api/appearances', $this->openAccount()->headers);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $catalog = self::decode($response);
        self::assertIsArray($catalog['appearances']);
        self::assertIsArray($catalog['equipment']);
        self::assertCount(4, array_filter($catalog['appearances'], static fn ($entry): bool => \is_array($entry) && true === $entry['selectable']));
        self::assertCount(6, array_filter($catalog['equipment'], static fn ($entry): bool => \is_array($entry) && true === $entry['selectable']));
        $models = [];
        foreach ($catalog['appearances'] as $model) {
            self::assertIsArray($model);
            self::assertIsString($model['key']);
            $models[$model['key']] = $model;
            foreach (['imageUrls' => 512, 'thumbnailUrls' => 256, 'foregroundUrls' => 256] as $field => $size) {
                if (!isset($model[$field]) && 'foregroundUrls' === $field) {
                    continue;
                }
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

    public function testTheTwoFlippersHaveOppositeOrientationsAndMatchingMirroredPivots(): void
    {
        $catalog = self::decode($this->get('/api/appearances', $this->openAccount()->headers));
        self::assertIsArray($catalog['equipment']);
        $item = array_find($catalog['equipment'], static fn ($entry): bool => \is_array($entry) && 'ACADEMIC_FLIPPERS' === $entry['key'] && 'v1' === $entry['version']);
        self::assertIsArray($item);
        self::assertIsArray($item['layers']);
        /** @var array{sourceUrls:array{back:string,front:string},pivot:array{x:float}}|null $rear */
        $rear = array_find($item['layers'], static fn ($layer): bool => \is_array($layer) && 'footRear' === $layer['attachment']);
        /** @var array{sourceUrls:array{back:string,front:string},pivot:array{x:float}}|null $front */
        $front = array_find($item['layers'], static fn ($layer): bool => \is_array($layer) && 'footFront' === $layer['attachment']);
        self::assertIsArray($rear);
        self::assertIsArray($front);
        self::assertSame($front['sourceUrls']['front'], $rear['sourceUrls']['back']);
        self::assertSame($front['sourceUrls']['back'], $rear['sourceUrls']['front']);
        self::assertEqualsWithDelta(1 - $front['pivot']['x'], $rear['pivot']['x'], 0.000001);
    }

    public function testCorrectedGogglesAndHistoricalReplayVersionAreBothServed(): void
    {
        $catalog = self::decode($this->get('/api/appearances', $this->openAccount()->headers));
        self::assertIsArray($catalog['equipment']);
        $goggles = array_filter($catalog['equipment'], static fn ($entry): bool => \is_array($entry) && 'PURSUIT_GOGGLES' === $entry['key']);
        $active = array_values(array_filter($goggles, static fn ($entry): bool => \is_array($entry) && true === $entry['selectable']));
        self::assertCount(1, $active);
        self::assertIsArray($active[0]);
        self::assertSame('v4', $active[0]['version']);
        $historical = array_find($goggles, static fn ($entry): bool => \is_array($entry) && 'v3' === $entry['version']);
        self::assertIsArray($historical);
        self::assertFalse($historical['selectable']);
        self::assertNotSame($historical['layers'], $active[0]['layers']);
    }

    public function testOneAdminGripCalibrationFeedsEveryCombatState(): void
    {
        $headers = $this->openAccount()->headers;
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $source = $connection->fetchOne("SELECT manifest_json FROM game_artwork_pack WHERE pack_key = 'STRENGTH_V2'");
        self::assertIsString($source);
        /** @var array{stanceMode?:string,imageUrls:array<string,array<string,string>>,thumbnailUrls:array<string,array<string,string>>,attachments:array<string,array<string,array<string,array<string,int|float>>>>} $manifest */
        $manifest = json_decode($source, true, flags: \JSON_THROW_ON_ERROR);
        $manifest['stanceMode'] = 'guard';
        foreach (['imageUrls', 'thumbnailUrls'] as $field) {
            foreach ($manifest[$field] as &$series) {
                $series = ['idle' => $series['idle']];
            }
            unset($series);
        }
        foreach ($manifest['attachments'] as &$anchors) {
            foreach ($anchors as &$series) {
                $series = ['idle' => $series['idle']];
            }
            unset($series);
        }
        unset($anchors);
        $manifest['attachments']['back']['hand']['idle']['x'] = 0.77;
        try {
            $connection->executeStatement("UPDATE game_artwork_pack SET manifest_json = ? WHERE pack_key = 'STRENGTH_V2'", [json_encode($manifest, \JSON_THROW_ON_ERROR)]);
            $catalog = self::decode($this->get('/api/appearances', $headers));
            self::assertIsArray($catalog['appearances']);
            $avatar = array_find($catalog['appearances'], static fn ($row): bool => \is_array($row) && 'STRENGTH_V2' === $row['key']);
            self::assertIsArray($avatar);
            self::assertArrayNotHasKey('stanceMode', $avatar);
            self::assertIsArray($avatar['attachments']);
            self::assertIsArray($avatar['attachments']['back']);
            self::assertIsArray($avatar['attachments']['back']['hand']);
            foreach (GameArtworkPack::POSES as $pose) {
                self::assertIsArray($avatar['attachments']['back']['hand'][$pose]);
                self::assertSame(0.77, $avatar['attachments']['back']['hand'][$pose]['x']);
            }
        } finally {
            $connection->executeStatement("UPDATE game_artwork_pack SET manifest_json = ? WHERE pack_key = 'STRENGTH_V2'", [$source]);
        }
    }

    public function testPublishedPosesAreDistinctAndAdminCalibrationOnlyChangesTheChosenPose(): void
    {
        $headers = $this->openAccount()->headers;
        $catalog = self::decode($this->get('/api/appearances', $headers));
        self::assertIsArray($catalog['appearances']);
        foreach ($catalog['appearances'] as $model) {
            self::assertIsArray($model);
            if (true !== $model['selectable']) {
                continue;
            }
            /** @var array{back:array<string,string>,front:array<string,string>} $urls */
            $urls = $model['imageUrls'];
            foreach ($urls as $series) {
                $hashes = array_map(static fn (string $url): string|false => hash_file('sha256', self::fileFor($url)), $series);
                self::assertCount(\count(GameArtworkPack::POSES), array_unique($hashes), 'Les poses publiées ne doivent pas répéter une garde.');
            }
        }
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $source = $connection->fetchOne("SELECT manifest_json FROM game_artwork_pack WHERE pack_key = 'STRENGTH_V3'");
        self::assertIsString($source);
        /** @var array{attachments:array<string,array<string,array<string,array<string,int|float>>>>} $manifest */
        $manifest = json_decode($source, true, flags: \JSON_THROW_ON_ERROR);
        $idle = $manifest['attachments']['back']['hand']['idle'];
        $manifest['attachments']['back']['hand']['attack']['x'] = 0.77;
        try {
            $connection->executeStatement("UPDATE game_artwork_pack SET manifest_json = ? WHERE pack_key = 'STRENGTH_V3'", [json_encode($manifest, \JSON_THROW_ON_ERROR)]);
            $updated = self::decode($this->get('/api/appearances', $headers));
            self::assertIsArray($updated['appearances']);
            $avatar = array_find($updated['appearances'], static fn ($row): bool => \is_array($row) && 'STRENGTH_V3' === $row['key']);
            self::assertIsArray($avatar);
            /** @var array<string,array<string,array<string,array<string,int|float>>>> $anchors */
            $anchors = $avatar['attachments'];
            self::assertSame(0.77, $anchors['back']['hand']['attack']['x']);
            self::assertSame($idle, $anchors['back']['hand']['idle']);
        } finally {
            $connection->executeStatement("UPDATE game_artwork_pack SET manifest_json = ? WHERE pack_key = 'STRENGTH_V3'", [$source]);
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
