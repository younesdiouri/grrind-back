<?php

declare(strict_types=1);

namespace App\Tests\Combat;

use App\Tests\Support\ApiTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /api/enemies` — le catalogue dont un écran de sélection a besoin, voir le docblock
 * d'`EnemiesController`.
 */
final class EnemiesTest extends ApiTestCase
{
    public function testTheCatalogueIsRenderedWithTranslatedNamesAndBothOrdinaryEnemiesAndBosses(): void
    {
        $bob = $this->openAccount();

        $response = $this->get('/api/enemies', $bob->headers);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $content = $response->getContent();
        self::assertIsString($content);
        $entries = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($entries);
        self::assertNotEmpty($entries);

        $byKey = [];

        foreach ($entries as $entry) {
            self::assertIsArray($entry);
            self::assertSame(
                ['key', 'name', 'minimumLevel', 'hp', 'damage', 'mitigationPercent', 'extraTurnPercent', 'dodgePercent'],
                array_keys($entry),
            );
            self::assertIsString($entry['key']);
            self::assertIsString($entry['name']);
            self::assertNotSame('', $entry['name'], \sprintf('"%s" est rendu sans nom traduit.', $entry['key']));
            self::assertIsInt($entry['minimumLevel']);
            self::assertGreaterThanOrEqual(1, $entry['minimumLevel']);

            $byKey[$entry['key']] = $entry;
        }

        // Le catalogue livré : un ennemi ordinaire au niveau 1, et au moins un boss dont le
        // `minimum_level` dépasse celui du dernier ennemi qu'un compte neuf rencontrerait.
        self::assertArrayHasKey('SAND_JACKAL', $byKey);
        self::assertSame(1, $byKey['SAND_JACKAL']['minimumLevel']);

        self::assertArrayHasKey('DUNE_SOVEREIGN', $byKey);
        self::assertSame(10, $byKey['DUNE_SOVEREIGN']['minimumLevel']);
    }

    public function testListingEnemiesRefusesAnAnonymousCaller(): void
    {
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->get('/api/enemies')->getStatusCode());
    }
}
