<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Shared\Domain\Appearance;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\HttpFoundation\Response;

/** L'apparence du joueur et le catalogue des modèles (#289). */
final class AppearanceTest extends ApiTestCase
{
    public function testEveryAccountStartsWithTheDefaultModelAndCanChooseOne(): void
    {
        $headers = $this->openAccount()->headers;
        self::assertSame(Appearance::default()->value, self::decode($this->get('/api/me', $headers))['appearance']);

        $response = $this->send('PATCH', '/api/me', ['appearance' => Appearance::Murid->value], $headers);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(Appearance::Murid->value, self::decode($response)['appearance']);
    }

    public function testRejectsAModelOutsideTheCatalogue(): void
    {
        $response = $this->send('PATCH', '/api/me', ['appearance' => 'GOLDEN_KNIGHT'], $this->openAccount()->headers);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    /**
     * Chaque URL du catalogue désigne un PNG livré sous `public/` : un modèle ajouté à l'enum
     * sans ses douze images échoue ici plutôt qu'en 404 sur le téléphone.
     */
    public function testTheCatalogueServesBothViewsAndThumbnailsOfEveryModel(): void
    {
        $response = $this->get('/api/appearances', $this->openAccount()->headers);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $appearances = self::decode($response)['appearances'];
        self::assertIsArray($appearances);
        self::assertSame(array_column(Appearance::cases(), 'value'), array_column($appearances, 'key'));

        foreach ($appearances as $model) {
            self::assertIsArray($model);
            foreach (['imageUrls' => 1024, 'thumbnailUrls' => 256] as $field => $side) {
                self::assertIsArray($model[$field]);
                self::assertSame(['back', 'front'], array_keys($model[$field]));
                foreach ($model[$field] as $poses) {
                    self::assertIsArray($poses);
                    self::assertSame(['idle', 'attack', 'hit'], array_keys($poses));
                    foreach ($poses as $url) {
                        self::assertIsString($url);
                        self::assertMatchesRegularExpression('#^https?://[^/]+/appearances/#', $url);
                        $file = \dirname(__DIR__, 2).'/public'.parse_url($url, \PHP_URL_PATH);
                        self::assertFileExists($file);
                        $dimensions = getimagesize($file);
                        self::assertNotFalse($dimensions);
                        self::assertSame([$side, $side], [$dimensions[0], $dimensions[1]], $file);
                    }
                }
            }
        }
    }
}
