<?php

declare(strict_types=1);

namespace App\Tests\Progression;

use App\Admin\Domain\GameActivityType;
use App\Admin\Domain\GameDiscipline;
use App\Admin\Infrastructure\GameRulesetPublisher;
use App\Progression\Domain\DailyLoad;
use App\Progression\Domain\XpCalculator;
use App\Progression\Domain\XpRates;
use App\Progression\UI\Http\Response\SportCatalogResource;
use App\Shared\Application\FrozenGameRulesets;
use App\Shared\Application\GameRulesets;
use App\Shared\Domain\Activity\AttributeSplit;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Infrastructure\Translation\DisciplineTranslator;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Yaml;

/**
 * @phpstan-import-type Entry from SportCatalogResource
 *
 * @phpstan-type Catalog array{rulesetVersion: string, revision: int, reference: array{durationSeconds: int, unit: string}, disciplines: list<Entry>}
 */
final class SportCatalogTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->client->disableReboot();
    }

    public function testPublishedSportsExposeTheRealBaseCalculationAndConcreteAliases(): void
    {
        $account = $this->openAccount();
        $response = $this->get('/api/sports', $account->headers);
        self::assertSame(200, $response->getStatusCode());
        $body = self::catalogBody($response);
        self::assertSame(['durationSeconds' => 1800, 'unit' => 'XP'], $body['reference']);
        self::assertSame(self::getContainer()->get(GameRulesets::class)->version(), $body['rulesetVersion']);
        self::assertIsArray($body['disciplines']);
        $entries = array_column($body['disciplines'], null, 'discipline');
        self::assertCount(12, $entries);

        $rackets = $entries['RACKET_SPORTS'];
        self::assertContains('tennis', $rackets['aliases']);
        self::assertTrue($rackets['creditsXp']);
        self::assertContains(['source' => 'APPLE_HEALTH', 'activityType' => 'tennis', 'sport' => 'tennis'], $rackets['providerMappings']);
        $award = self::getContainer()->get(XpCalculator::class)->calculate(Discipline::RacketSports, 1800, [], DailyLoad::untouched());
        self::assertSame($award->amount(), $rackets['baseXp']);
        self::assertSame($award->attributeGains->toArray(), $rackets['baseAttributeGains']);
        self::assertSame(array_change_key_case($award->attributePercentages), $rackets['attributePercentages']);

        self::assertFalse($entries['WALKING']['creditsXp']);
        self::assertSame(0, $entries['WALKING']['baseXp']);
        self::assertNull($entries['WALKING']['baseAttributeGains']);
        self::assertNull($entries['WALKING']['attributePercentages']);
        self::assertNull($entries['WALKING']['dailyCapXp']);
        // #251 reste ouvert : aucun alias inventé pour des sports non traduits.
        self::assertNotContains('martialArts', array_merge(...array_column($entries, 'aliases')));

        /** @var array{components: array{schemas: array{SportCatalog: array{required: list<string>}, SportCatalogEntry: array{required: list<string>, properties: array<string, array{nullable: bool}>}}}} $spec */
        $spec = Yaml::parseFile(\dirname(__DIR__, 2).'/openapi.yaml');
        self::assertSame($spec['components']['schemas']['SportCatalog']['required'], array_keys($body));
        self::assertSame($spec['components']['schemas']['SportCatalogEntry']['required'], array_keys($rackets));
        foreach (['attributePercentages', 'baseAttributeGains', 'dailyCapXp'] as $nullable) {
            self::assertTrue($spec['components']['schemas']['SportCatalogEntry']['properties'][$nullable]['nullable']);
        }
    }

    public function testAuthenticationAndConditionalRead(): void
    {
        self::assertSame(401, $this->get('/api/sports')->getStatusCode());
        $account = $this->openAccount();
        $response = $this->get('/api/sports', $account->headers);
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-cache'));
        $etag = $response->getEtag();
        self::assertNotNull($etag);
        $response = $this->get('/api/sports', [...$account->headers, 'If-None-Match' => $etag]);
        self::assertSame(304, $response->getStatusCode());
        self::assertSame('', $response->getContent());

        $response = $this->get('/api/sports', [...$account->headers, 'Accept-Language' => 'fr', 'If-None-Match' => $etag]);
        self::assertSame(200, $response->getStatusCode());
        self::assertContains('Accept-Language', $response->getVary());
        $entries = array_column(self::catalogBody($response)['disciplines'], null, 'discipline');
        self::assertSame(self::getContainer()->get(DisciplineTranslator::class)->labelOf(Discipline::RacketSports, 'fr'), $entries['RACKET_SPORTS']['name']);
    }

    public function testDraftsStayInvisibleAndPublicationRefreshesNamesGainsAndMappings(): void
    {
        $account = $this->openAccount();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $publisher = self::getContainer()->get(GameRulesetPublisher::class);
        // Les fixtures remplacent aussi les équipements en brouillon : synchroniser ce socle.
        $this->publish($publisher, $manager);
        $response = $this->get('/api/sports', $account->headers);
        $before = self::catalogBody($response);
        $etag = $response->getEtag();
        self::assertNotNull($etag);
        $discipline = $manager->getRepository(GameDiscipline::class)->findOneBy(['discipline' => Discipline::RacketSports]);
        self::assertInstanceOf(GameDiscipline::class, $discipline);
        $translations = $discipline->getTranslations();
        $cap = $discipline->getDailyCapXp();
        self::assertIsInt($cap);
        $split = $discipline->getSplit();
        $tennis = $manager->getRepository(GameActivityType::class)->findBy(['normalizedSport' => 'tennis']);
        self::assertNotEmpty($tennis);
        $active = array_map(static fn (GameActivityType $mapping): bool => $mapping->isActive(), $tennis);
        try {
            $changed = $translations;
            $changed['en']['label'] = 'Published racket label #354';
            $discipline->setTranslations($changed);
            $manager->flush();
            self::assertSame($before, self::catalogBody($this->get('/api/sports', $account->headers)));
            $this->publish($publisher, $manager);

            $response = $this->get('/api/sports', [...$account->headers, 'If-None-Match' => $etag]);
            self::assertSame(200, $response->getStatusCode());
            $renamed = self::catalogBody($response);
            self::assertSame($before['rulesetVersion'], $renamed['rulesetVersion']);
            self::assertGreaterThan($before['revision'], $renamed['revision']);
            self::assertSame($changed['en']['label'], array_column($renamed['disciplines'], null, 'discipline')['RACKET_SPORTS']['name']);

            // Le reset HTTP vide l'identity map Doctrine entre deux requêtes.
            $discipline = $manager->getRepository(GameDiscipline::class)->findOneBy(['discipline' => Discipline::RacketSports]);
            self::assertInstanceOf(GameDiscipline::class, $discipline);
            $tennis = $manager->getRepository(GameActivityType::class)->findBy(['normalizedSport' => 'tennis']);
            $discipline->setDailyCapXp($cap + 60);
            $discipline->setSplit(['strength' => 80, 'endurance' => 10, 'mobility' => 5, 'dexterity' => 5]);
            foreach ($tennis as $mapping) {
                $mapping->setActive(false);
            }
            $this->publish($publisher, $manager);
            $body = self::catalogBody($this->get('/api/sports', $account->headers));
            self::assertNotSame($renamed['rulesetVersion'], $body['rulesetVersion']);
            $rackets = array_column($body['disciplines'], null, 'discipline')['RACKET_SPORTS'];
            self::assertSame(30, $rackets['baseXp']);
            self::assertSame($cap + 60, $rackets['dailyCapXp']);
            self::assertSame(['strength' => 24, 'endurance' => 3, 'mobility' => 2, 'dexterity' => 1], $rackets['baseAttributeGains']);
            self::assertSame(['strength' => 80, 'endurance' => 10, 'mobility' => 5, 'dexterity' => 5], $rackets['attributePercentages']);
            self::assertNotContains('tennis', $rackets['aliases']);
            self::assertNotContains('tennis', array_column($rackets['providerMappings'], 'sport'));
        } finally {
            $discipline = $manager->getRepository(GameDiscipline::class)->findOneBy(['discipline' => Discipline::RacketSports]);
            self::assertInstanceOf(GameDiscipline::class, $discipline);
            $tennis = $manager->getRepository(GameActivityType::class)->findBy(['normalizedSport' => 'tennis']);
            $discipline->setTranslations($translations);
            $discipline->setDailyCapXp($cap);
            $discipline->setSplit($split);
            foreach ($tennis as $index => $mapping) {
                $mapping->setActive($active[$index]);
            }
            $this->publish($publisher, $manager);
        }
    }

    public function testInactiveDisciplinesAreOmittedEvenIfTheirMappingsRemainInTheSnapshot(): void
    {
        $published = self::getContainer()->get(GameRulesets::class);
        $snapshot = $published->snapshot();
        /** @var list<array{discipline: string, active: bool}> $rows */
        $rows = $snapshot['disciplines'];
        foreach ($rows as &$row) {
            if ('RACKET_SPORTS' === $row['discipline']) {
                $row['active'] = false;
            }
        }
        unset($row);
        $snapshot['disciplines'] = $rows;
        $rulesets = new FrozenGameRulesets($snapshot, $published->version(), $published->revision());
        $calculator = new XpCalculator(XpRates::runtime($rulesets), AttributeSplit::runtime($rulesets), $rulesets);
        $resource = SportCatalogResource::from($rulesets, $calculator, new DisciplineTranslator($rulesets), 'en');

        self::assertNotContains('RACKET_SPORTS', array_column($resource->disciplines, 'discipline'));
        self::assertNotContains('tennis', array_merge(...array_column($resource->disciplines, 'aliases')));
    }

    /** @return Catalog */
    private static function catalogBody(Response $response): array
    {
        /** @var Catalog $body */
        $body = self::decode($response);

        return $body;
    }

    private function publish(GameRulesetPublisher $publisher, EntityManagerInterface $manager): void
    {
        $manager->wrapInTransaction(static function () use ($publisher, $manager): void {
            $publisher->publish($manager, 'sport-catalog-test-354');
        });
        $publisher->invalidateAfterCommit();
    }
}
