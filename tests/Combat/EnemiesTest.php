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
    /**
     * Niché sous `enemies`, jamais un tableau nu à la racine — voir le docblock
     * d'`EnemyCatalogResource`. Un seul champ aujourd'hui, mais c'est l'enveloppe qui laisse
     * la place à un champ frère plus tard sans rien casser.
     */
    public function testTheResponseIsAnObjectNestingTheListUnderEnemies(): void
    {
        $bob = $this->openAccount();

        $payload = self::decode($this->get('/api/enemies', $bob->headers));

        self::assertSame(['enemies'], array_keys($payload));

        $entries = $payload['enemies'];
        self::assertIsArray($entries);
        self::assertNotEmpty($entries);
    }

    /**
     * L'ordre des champs d'une entrée est du contrat versionné — même règle que
     * {@see UI\Http\Response\BattleResourcePayloadTest} pour `BattleResource`.
     */
    public function testTheKeyOrderOfAnEntryIsFixed(): void
    {
        $entries = $this->catalogueEntries();

        $first = $entries[0];
        self::assertIsArray($first);
        self::assertSame(
            ['key', 'name', 'minimumLevel', 'hp', 'damage', 'mitigationPercent', 'extraTurnPercent', 'dodgePercent'],
            array_keys($first),
        );
    }

    public function testTheCatalogueIsRenderedWithTranslatedNamesAndBothOrdinaryEnemiesAndBosses(): void
    {
        $byKey = [];

        foreach ($this->catalogueEntries() as $entry) {
            self::assertIsArray($entry);
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

    /**
     * @return list<mixed>
     */
    private function catalogueEntries(): array
    {
        $bob = $this->openAccount();

        $payload = self::decode($this->get('/api/enemies', $bob->headers));

        $entries = $payload['enemies'];
        self::assertIsArray($entries);

        return array_values($entries);
    }
}
