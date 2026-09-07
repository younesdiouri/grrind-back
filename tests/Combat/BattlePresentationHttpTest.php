<?php

declare(strict_types=1);

namespace App\Tests\Combat;

use App\Admin\Domain\GameRuleset;
use App\Admin\Infrastructure\GameRulesetPublisher;
use App\Shared\Infrastructure\Config\GameRulesetVersion;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Battles;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final class BattlePresentationHttpTest extends ApiTestCase
{
    use Battles;

    public function testCatalogueCreationReplayAndHistoricalDetailsUsePublishedPresentation(): void
    {
        $account = $this->openAccount();
        $historical = $this->recordBattle($account, new DateTimeImmutable('-1 day'));
        $unknown = $this->recordBattle($account, new DateTimeImmutable('-2 days'), enemyKey: 'REMOVED_KEY');
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $ruleset = $manager->find(GameRuleset::class, 1);
        self::assertInstanceOf(GameRuleset::class, $ruleset);
        $original = $ruleset->snapshot();
        $snapshot = $original;
        self::assertIsArray($snapshot['combat']);
        self::assertIsArray($snapshot['combat']['enemies']);
        $index = null;
        foreach ($snapshot['combat']['enemies'] as $i => $enemy) {
            self::assertIsArray($enemy);
            if ('SAND_JACKAL' === $enemy['key']) {
                $index = $i;
            }
        }
        self::assertNotNull($index);
        self::assertIsArray($snapshot['combat']['enemies'][$index]);
        $paths = ['idle' => str_repeat('1', 40).'.png', 'attack' => str_repeat('2', 40).'.png', 'hit' => str_repeat('3', 40).'.png'];
        $dialogue = "je suis la paresse, laissez tomber, ce jeu n'est pas fait pour vous.";
        try {
            $legacy = self::decode($this->get('/api/battles/'.$historical, $account->headers));
            self::assertIsArray($legacy['enemy']);
            self::assertNull($legacy['enemy']['imageUrls']);
            self::assertNull($legacy['enemy']['introduction']);
            $snapshot['combat']['enemies'][$index]['image_paths'] = $paths;
            $snapshot['combat']['enemies'][$index]['translations'] = ['fr' => ['name' => 'Chacal', 'introduction' => $dialogue], 'en' => ['name' => 'Jackal', 'introduction' => 'Give up.']];
            $this->publishSnapshot($snapshot);
            $catalogue = self::decode($this->get('/api/enemies', $account->headers + ['Accept-Language' => 'fr']));
            self::assertIsArray($catalogue['enemies']);
            $enemy = $catalogue['enemies'][0];
            self::assertIsArray($enemy);
            self::assertSame('SAND_JACKAL', $enemy['key']);
            self::assertSame($dialogue, $enemy['introduction']);
            $expected = [];
            foreach ($paths as $pose => $path) {
                $expected[$pose] = 'http://localhost/game-images/'.$path;
            }
            self::assertSame($expected, $enemy['imageUrls']);

            $headers = $account->headers + ['Accept-Language' => 'fr', 'Idempotency-Key' => 'poses'];
            $created = self::decode($this->post('/api/battles', ['enemy' => 'SAND_JACKAL'], $headers));
            self::assertResponseStatusCodeSame(201);
            self::assertIsArray($created['enemy']);
            self::assertSame($expected, $created['enemy']['imageUrls']);
            self::assertSame($dialogue, $created['enemy']['introduction']);
            $replayed = self::decode($this->post('/api/battles', ['enemy' => 'SAND_JACKAL'], $headers));
            self::assertEquals($created, $replayed);
            self::assertIsString($created['id']);
            $detail = self::decode($this->get('/api/battles/'.$created['id'], $account->headers + ['Accept-Language' => 'en']));
            self::assertIsArray($detail['enemy']);
            self::assertSame('Give up.', $detail['enemy']['introduction']);
            self::assertSame($expected, $detail['enemy']['imageUrls']);
            foreach (['events', 'rewards', 'player', 'turns', 'result'] as $field) {
                self::assertEquals($created[$field], $detail[$field]);
            }

            // Un ancien combat reçoit la présentation courante, jamais les nouvelles stats.
            $snapshot['combat']['enemies'][$index]['active'] = false;
            $snapshot['combat']['enemies'][$index]['hp'] = 999;
            $this->publishSnapshot($snapshot);
            $detail = self::decode($this->get('/api/battles/'.$historical, $account->headers + ['Accept-Language' => 'fr']));
            self::assertResponseIsSuccessful();
            self::assertIsArray($detail['enemy']);
            self::assertSame($expected, $detail['enemy']['imageUrls']);
            self::assertSame($legacy['enemy']['hp'], $detail['enemy']['hp']);
            self::assertSame($legacy['events'], $detail['events']);
            self::assertSame($legacy['rewards'], $detail['rewards']);
            $missing = self::decode($this->get('/api/battles/'.$unknown, $account->headers));
            self::assertResponseIsSuccessful();
            self::assertIsArray($missing['enemy']);
            self::assertNull($missing['enemy']['imageUrls']);
            self::assertNull($missing['enemy']['introduction']);

            unset($snapshot['combat']['enemies'][$index]['image_paths']);
            $this->publishSnapshot($snapshot);
            $dialogueOnly = self::decode($this->get('/api/battles/'.$historical, $account->headers + ['Accept-Language' => 'fr']));
            self::assertIsArray($dialogueOnly['enemy']);
            self::assertNull($dialogueOnly['enemy']['imageUrls']);
            self::assertSame($dialogue, $dialogueOnly['enemy']['introduction']);
        } finally {
            $this->publishSnapshot($original);
        }
    }

    /**
     * Les fixtures couvrent aussi des snapshots historiques indépendamment de l'éditeur.
     *
     * @param array<string, mixed> $snapshot
     */
    private function publishSnapshot(array $snapshot): void
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $ruleset = $manager->find(GameRuleset::class, 1);
        self::assertInstanceOf(GameRuleset::class, $ruleset);
        $ruleset->publish($snapshot, GameRulesetVersion::of($snapshot));
        $manager->flush();
        self::getContainer()->get(GameRulesetPublisher::class)->invalidateAfterCommit();
    }
}
