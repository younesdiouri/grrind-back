<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Domain\GameActivityType;
use App\Admin\Domain\GameDiscipline;
use App\Admin\Domain\GameEnemy;
use App\Admin\Domain\GameItem;
use App\Admin\Domain\GameLevel;
use App\Admin\Domain\GameLootTable;
use App\Admin\Domain\GameRuleset;
use App\Admin\Domain\GameSettings;
use App\Admin\Domain\GameTitle;
use App\Admin\Infrastructure\GameRulesetPublisher;
use App\Identity\Domain\Role;
use App\Identity\Infrastructure\Doctrine\UserRepository;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Form;

/** Le CRUD de configuration passe par les formulaires EasyAdmin réellement servis. */
final class GameCrudHttpTest extends ApiTestCase
{
    public function testLootTableCanBeCreatedUpdatedDeactivatedAndDeletedThroughAdmin(): void
    {
        $this->loginAdmin('loot-crud-admin@grrind.app');
        $key = 'http_loot_'.bin2hex(random_bytes(4));
        $crawler = $this->client->request('GET', '/admin/loot-table/new');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="GameLootTable[_token]"]')->attr('value');
        self::assertIsString($token);

        // BrowserKit does not materialize an empty EasyAdmin collection. The HTTP payload
        // nevertheless remains identical to the compound entry submitted by an administrator.
        $this->client->request('POST', '/admin/loot-table/new', [
            'GameLootTable' => [
                'kind' => 'workout',
                'key' => $key,
                'active' => '1',
                'sortOrder' => (string) random_int(4_000_001, 5_000_000),
                'eligibility' => [
                    'disciplines' => [],
                    'minimum_duration_minutes' => '1',
                    'minimum_level' => '1',
                ],
                'coinsMinimum' => '0',
                'coinsMaximum' => '1',
                'entries' => [
                    ['weight' => '1'],
                ],
                '_token' => $token,
            ],
        ]);
        self::assertResponseRedirects();

        $tables = self::getContainer()->get('doctrine')->getRepository(GameLootTable::class);
        $table = $tables->findOneBy(['key' => $key, 'kind' => 'workout']);
        self::assertInstanceOf(GameLootTable::class, $table);
        self::assertTrue($table->isActive());
        self::assertSame([['item' => null, 'weight' => 1]], $table->getEntries());
        self::assertSame(['disciplines' => [], 'minimum_duration_minutes' => 1, 'minimum_level' => 1], $table->getEligibility());

        $crawler = $this->client->request('GET', '/admin/loot-table/'.$table->getId()->toRfc4122().'/edit');
        self::assertResponseIsSuccessful();
        $edit = $crawler->filter('form[name="GameLootTable"]')->form();
        $active = $edit['GameLootTable[active]'];
        self::assertInstanceOf(ChoiceFormField::class, $active);
        $active->untick();
        $edit->setValues(['GameLootTable[coinsMaximum]' => '2']);
        $this->client->submit($edit);
        self::assertResponseRedirects();
        self::getContainer()->get('doctrine')->getManager()->clear();
        $updated = $tables->findOneBy(['key' => $key, 'kind' => 'workout']);
        self::assertInstanceOf(GameLootTable::class, $updated);
        self::assertFalse($updated->isActive());
        self::assertSame(2, $updated->getCoinsMaximum());

        $this->delete('/admin/loot-table/'.$updated->getId()->toRfc4122().'/edit', '/admin/loot-table/'.$updated->getId()->toRfc4122().'/delete');
        self::getContainer()->get('doctrine')->getManager()->clear();
        $stillPublished = $tables->findOneBy(['key' => $key, 'kind' => 'workout']);
        self::assertInstanceOf(GameLootTable::class, $stillPublished);
        self::assertFalse($stillPublished->isActive());
    }

    public function testConfigurationIndexesExposeSearchFiltersAndStableSorts(): void
    {
        $this->loginAdmin('catalog-index-admin@grrind.app');

        foreach (['/admin/item', '/admin/title', '/admin/enemy', '/admin/loot-table'] as $path) {
            $crawler = $this->client->request('GET', $path);
            self::assertResponseIsSuccessful();
            self::assertGreaterThan(0, $crawler->filter('th[data-column="key"].searchable')->count());
            self::assertGreaterThan(0, $crawler->filter('th[data-column="sortOrder"] a')->count());

            $filters = $this->client->request('GET', $path.'/render-filters');
            self::assertResponseIsSuccessful();
            self::assertGreaterThan(0, $filters->filter('form[data-ea-filters-form-id]')->count());
        }

        $settings = $this->client->request('GET', '/admin/settings');
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $settings->filter('th[data-column="id"] a')->count());
    }

    public function testRemainingBalanceCrudRoutesSupportTheirSafeLifecycle(): void
    {
        $this->loginAdmin('remaining-balance-crud-admin@grrind.app');
        $doctrine = self::getContainer()->get('doctrine');
        $discipline = $doctrine->getRepository(GameDiscipline::class)->findOneBy(['active' => true]);
        $level = $doctrine->getRepository(GameLevel::class)->findOneBy(['level' => 1]);
        self::assertInstanceOf(GameDiscipline::class, $discipline);
        self::assertInstanceOf(GameLevel::class, $level);
        $disciplineId = $discipline->getId()->toRfc4122();
        $levelId = $level->getId()->toRfc4122();

        foreach ([
            ['discipline', $disciplineId],
            ['level', $levelId],
        ] as [$path, $id]) {
            $this->client->request('GET', '/admin/'.$path);
            self::assertResponseIsSuccessful();
            $this->client->request('GET', '/admin/'.$path.'/new');
            self::assertResponseIsSuccessful();
            $this->client->request('GET', '/admin/'.$path.'/'.$id.'/edit');
            self::assertResponseIsSuccessful();
        }

        $this->delete('/admin/discipline/'.$disciplineId.'/edit', '/admin/discipline/'.$disciplineId.'/delete');
        $this->delete('/admin/level/'.$levelId.'/edit', '/admin/level/'.$levelId.'/delete');
        self::getContainer()->get('doctrine')->getManager()->clear();
        self::assertNotNull(self::getContainer()->get('doctrine')->getRepository(GameDiscipline::class)->find($disciplineId));
        self::assertNotNull(self::getContainer()->get('doctrine')->getRepository(GameLevel::class)->find($levelId));

        $providerType = 'HTTP_INACTIVE_'.bin2hex(random_bytes(4));
        $this->client->request('GET', '/admin/activity-type');
        self::assertResponseIsSuccessful();
        $crawler = $this->client->request('GET', '/admin/activity-type/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="GameActivityType"]')->form();
        $active = $form['GameActivityType[active]'];
        self::assertInstanceOf(ChoiceFormField::class, $active);
        $active->untick();
        $form->setValues([
            'GameActivityType[source]' => '0',
            'GameActivityType[providerType]' => $providerType,
            'GameActivityType[discipline]' => '0',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects();

        $mapping = self::getContainer()->get('doctrine')->getRepository(GameActivityType::class)->findOneBy(['providerType' => $providerType]);
        self::assertInstanceOf(GameActivityType::class, $mapping);
        $mappingId = $mapping->getId()->toRfc4122();
        $edit = $this->client->request('GET', '/admin/activity-type/'.$mappingId.'/edit')->filter('form[name="GameActivityType"]')->form();
        $editedProviderType = $providerType.'_EDITED';
        $edit->setValues(['GameActivityType[providerType]' => $editedProviderType]);
        $this->client->submit($edit);
        self::assertResponseRedirects();
        $this->delete('/admin/activity-type/'.$mappingId.'/edit', '/admin/activity-type/'.$mappingId.'/delete');
        self::getContainer()->get('doctrine')->getManager()->clear();
        self::assertNull(self::getContainer()->get('doctrine')->getRepository(GameActivityType::class)->find($mappingId));
    }

    public function testTitleCanBeCreatedUpdatedDeactivatedAndDeletedThroughAdmin(): void
    {
        $this->loginAdmin();
        $key = 'http_admin_title_'.bin2hex(random_bytes(4));
        $crawler = $this->client->request('GET', '/admin/title/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="GameTitle"]')->form();
        self::assertInstanceOf(Form::class, $form);
        $form->setValues([
            'GameTitle[key]' => $key,
            'GameTitle[active]' => '1',
            'GameTitle[sortOrder]' => (string) random_int(1_000_000, 2_000_000),
            'GameTitle[conditionType]' => 'session_count',
            'GameTitle[threshold]' => '7',
            'GameTitle[discipline]' => '',
            'GameTitle[translations][fr][name]' => 'Titre HTTP',
            'GameTitle[translations][fr][hint]' => 'Sept séances',
            'GameTitle[translations][en][name]' => 'HTTP title',
            'GameTitle[translations][en][hint]' => 'Seven sessions',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects();

        $title = self::getContainer()->get('doctrine')->getRepository(GameTitle::class)->findOneBy(['key' => $key]);
        self::assertInstanceOf(GameTitle::class, $title);
        self::assertTrue($title->isActive());

        $crawler = $this->client->request('GET', '/admin/title/'.$title->getId()->toRfc4122().'/edit');
        self::assertResponseIsSuccessful();
        $edit = $crawler->filter('form[name="GameTitle"]')->form();
        $active = $edit['GameTitle[active]'];
        self::assertInstanceOf(ChoiceFormField::class, $active);
        $active->untick();
        $this->client->submit($edit);
        self::assertResponseRedirects();
        self::getContainer()->get('doctrine')->getManager()->clear();
        $deactivated = self::getContainer()->get('doctrine')->getRepository(GameTitle::class)->findOneBy(['key' => $key]);
        self::assertInstanceOf(GameTitle::class, $deactivated);
        self::assertFalse($deactivated->isActive());

        $deletePage = $this->client->request('GET', '/admin/title/'.$deactivated->getId()->toRfc4122().'/edit');
        $token = $deletePage->filter('#action-confirmation-form input[name="token"]')->attr('value');
        self::assertIsString($token);
        $this->client->request('POST', '/admin/title/'.$deactivated->getId()->toRfc4122().'/delete', ['token' => $token]);
        self::assertResponseRedirects();
        self::getContainer()->get('doctrine')->getManager()->clear();
        $stillPublished = self::getContainer()->get('doctrine')->getRepository(GameTitle::class)->findOneBy(['key' => $key]);
        self::assertInstanceOf(GameTitle::class, $stillPublished);
        self::assertFalse($stillPublished->isActive());
    }

    public function testInactiveEnemyCanBeCreatedUpdatedAndDeletedThroughAdmin(): void
    {
        $this->loginAdmin('enemy-crud-admin@grrind.app');
        $key = 'HTTP_ENEMY_'.bin2hex(random_bytes(4));
        $crawler = $this->client->request('GET', '/admin/enemy/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="GameEnemy"]')->form();
        $active = $form['GameEnemy[active]'];
        self::assertInstanceOf(ChoiceFormField::class, $active);
        $active->untick();
        $form->setValues([
            'GameEnemy[key]' => $key,
            'GameEnemy[sortOrder]' => (string) random_int(2_000_001, 3_000_000),
            'GameEnemy[minimumLevel]' => '99',
            'GameEnemy[hp]' => '100',
            'GameEnemy[damage]' => '1',
            'GameEnemy[mitigationPermille]' => '0',
            'GameEnemy[extraTurnPermille]' => '0',
            'GameEnemy[dodgePermille]' => '0',
            'GameEnemy[translations][fr][name]' => 'Ennemi HTTP',
            'GameEnemy[translations][en][name]' => 'HTTP enemy',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects();

        $enemy = self::getContainer()->get('doctrine')->getRepository(GameEnemy::class)->findOneBy(['key' => $key]);
        self::assertInstanceOf(GameEnemy::class, $enemy);
        self::assertFalse($enemy->isActive());

        $crawler = $this->client->request('GET', '/admin/enemy/'.$enemy->getId()->toRfc4122().'/edit');
        $edit = $crawler->filter('form[name="GameEnemy"]')->form();
        $edit->setValues(['GameEnemy[hp]' => '101']);
        $this->client->submit($edit);
        self::assertResponseRedirects();
        self::getContainer()->get('doctrine')->getManager()->clear();
        $updated = self::getContainer()->get('doctrine')->getRepository(GameEnemy::class)->findOneBy(['key' => $key]);
        self::assertInstanceOf(GameEnemy::class, $updated);
        self::assertSame(101, $updated->getHp());

        $this->delete('/admin/enemy/'.$updated->getId()->toRfc4122().'/edit', '/admin/enemy/'.$updated->getId()->toRfc4122().'/delete');
        self::getContainer()->get('doctrine')->getManager()->clear();
        self::assertNull(self::getContainer()->get('doctrine')->getRepository(GameEnemy::class)->findOneBy(['key' => $key]));
    }

    public function testEnemyAndChestLootPairsArePublishedAtomicallyThroughAdmin(): void
    {
        $this->loginAdmin('pair-crud-admin@grrind.app');
        $manager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $suffix = bin2hex(random_bytes(4));
        $enemy = $this->inactiveEnemy('PAIR_ENEMY_'.$suffix, 7_000_001);
        $enemyTable = $this->inactiveLoot('adversary', $enemy->getKey(), 7_000_001);
        $chest = $this->inactiveChest('PAIR_CHEST_'.$suffix, 7_000_002);
        $chestTable = $this->inactiveLoot('chest', $chest->getKey(), 7_000_002);
        foreach ([$enemy, $enemyTable, $chest, $chestTable] as $configuration) {
            $manager->persist($configuration);
        }
        $manager->flush();

        $pairs = [
            ['/admin/enemy/'.$enemy->getId()->toRfc4122().'/toggle-loot-pair', GameEnemy::class, $enemy->getId(), GameLootTable::class, $enemyTable->getId()],
            ['/admin/item/'.$chest->getId()->toRfc4122().'/toggle-loot-pair', GameItem::class, $chest->getId(), GameLootTable::class, $chestTable->getId()],
        ];
        $firstUrl = $pairs[0][0];
        $page = $this->client->request('GET', '/admin/enemy');
        self::assertResponseIsSuccessful();
        $token = $page->filterXPath('//form[contains(@action, "toggle-loot-pair")]//input[@name="token"]')->attr('value');
        self::assertIsString($token);
        $this->client->request('GET', $firstUrl);
        self::assertResponseStatusCodeSame(405);
        $this->client->request('POST', $firstUrl, ['token' => 'forged']);
        self::assertResponseStatusCodeSame(403);

        foreach ($pairs as [$url, $leftClass, $leftId, $rightClass, $rightId]) {
            $this->client->request('POST', $url, ['token' => $token]);
            self::assertResponseRedirects();
            $manager->clear();
            $left = $manager->find($leftClass, $leftId);
            $right = $manager->find($rightClass, $rightId);
            self::assertInstanceOf(GameEnemy::class === $leftClass ? GameEnemy::class : GameItem::class, $left);
            self::assertInstanceOf(GameLootTable::class, $right);
            self::assertTrue($left->isActive());
            self::assertTrue($right->isActive());

            $this->client->request('POST', $url, ['token' => $token]);
            self::assertResponseRedirects();
            $manager->clear();
            $left = $manager->find($leftClass, $leftId);
            $right = $manager->find($rightClass, $rightId);
            self::assertInstanceOf(GameEnemy::class === $leftClass ? GameEnemy::class : GameItem::class, $left);
            self::assertInstanceOf(GameLootTable::class, $right);
            self::assertFalse($left->isActive());
            self::assertFalse($right->isActive());
        }

        $orphan = $this->inactiveEnemy('ORPHAN_ENEMY_'.$suffix, 7_000_003, 100);
        $manager->persist($orphan);
        $manager->flush();
        $this->client->request('POST', '/admin/enemy/'.$orphan->getId()->toRfc4122().'/toggle-loot-pair', ['token' => $token]);
        self::assertResponseRedirects();
        $manager->clear();
        $orphan = $manager->find(GameEnemy::class, $orphan->getId());
        self::assertInstanceOf(GameEnemy::class, $orphan);
        self::assertFalse($orphan->isActive());

        // Les scénarios HTTP créent une paire réelle, mais l'état de référence migré reste
        // identique pour les scénarios suivants : la suppression est ici un nettoyage de test,
        // pas un chemin exposé à l'administration.
        foreach ([$enemy, $enemyTable, $chest, $chestTable, $orphan] as $configuration) {
            $managed = $manager->find($configuration::class, $configuration->getId());
            if (null !== $managed) {
                $manager->remove($managed);
            }
        }
        $manager->wrapInTransaction(static function () use ($manager): void {
            $manager->flush();
            $publisher = self::getContainer()->get(GameRulesetPublisher::class);
            self::assertInstanceOf(GameRulesetPublisher::class, $publisher);
            $publisher->publish($manager);
        });
        $publisher = self::getContainer()->get(GameRulesetPublisher::class);
        self::assertInstanceOf(GameRulesetPublisher::class, $publisher);
        $publisher->invalidateAfterCommit();
    }

    public function testSettingsStructuredFormUpdatesAndRestoresLootLuck(): void
    {
        $this->loginAdmin('settings-crud-admin@grrind.app');
        $settings = self::getContainer()->get('doctrine')->getRepository(GameSettings::class)->find(1);
        self::assertInstanceOf(GameSettings::class, $settings);
        $original = $settings->getLootLuck();
        $newFloor = $original['floor_percent'] + 1;

        $crawler = $this->client->request('GET', '/admin/settings/1/edit');
        self::assertResponseIsSuccessful();
        foreach ([
            'GameSettings[training][minimum_duration_seconds]',
            'GameSettings[xp][base_xp_per_hour]',
            'GameSettings[attributes][vitality][floor_permille]',
            'GameSettings[community][risala][week_timezone]',
            'GameSettings[notifications][quiet_hours_start_hour]',
        ] as $name) {
            self::assertCount(1, $crawler->filter(\sprintf('input[name="%s"]', $name)));
        }
        $form = $crawler->filter('form[name="GameSettings"]')->form();
        $form->setValues(['GameSettings[lootLuck][floor_percent]' => (string) $newFloor]);
        $this->client->submit($form);
        self::assertResponseRedirects();
        self::getContainer()->get('doctrine')->getManager()->clear();
        $updated = self::getContainer()->get('doctrine')->getRepository(GameSettings::class)->find(1);
        self::assertInstanceOf(GameSettings::class, $updated);
        self::assertSame($newFloor, $updated->getLootLuck()['floor_percent']);

        $restore = $this->client->request('GET', '/admin/settings/1/edit')->filter('form[name="GameSettings"]')->form();
        $restore->setValues(['GameSettings[lootLuck][floor_percent]' => (string) $original['floor_percent']]);
        $this->client->submit($restore);
        self::assertResponseRedirects();
    }

    public function testInactiveItemUploadCanBeCreatedUpdatedAndDeletedThroughAdmin(): void
    {
        $this->loginAdmin('item-crud-admin@grrind.app');
        $key = 'HTTP_ITEM_'.bin2hex(random_bytes(4));
        $path = tempnam(sys_get_temp_dir(), 'grrind-item-http-');
        self::assertIsString($path);
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL8ywAAAABJRU5ErkJggg==', true));
        try {
            $crawler = $this->client->request('GET', '/admin/item/new');
            $form = $crawler->filter('form[name="GameItem"]')->form();
            $active = $form['GameItem[active]'];
            self::assertInstanceOf(ChoiceFormField::class, $active);
            $active->untick();
            $upload = $form['GameItem[imagePath][file]'];
            self::assertInstanceOf(FileFormField::class, $upload);
            $upload->upload($path);
            $form->setValues([
                'GameItem[key]' => $key,
                'GameItem[sortOrder]' => (string) random_int(3_000_001, 4_000_000),
                'GameItem[rarity]' => 'COMMON',
                'GameItem[kind]' => 'EQUIPMENT',
                'GameItem[slot]' => 'FEET',
                'GameItem[priceCoins]' => '1',
                'GameItem[shopMinimumLevel]' => '',
                'GameItem[translations][fr][name]' => 'Objet HTTP',
                'GameItem[translations][en][name]' => 'HTTP item',
            ]);
            $this->client->submit($form);
            self::assertResponseRedirects();
            $item = self::getContainer()->get('doctrine')->getRepository(GameItem::class)->findOneBy(['key' => $key]);
            self::assertInstanceOf(GameItem::class, $item);
            self::assertFalse($item->isActive());
            self::assertNotSame('placeholder.png', $item->getImagePath());
            $this->client->request('GET', '/game-images/'.$item->getImagePath());
            self::assertResponseIsSuccessful();
            self::assertSame('image/png', $this->client->getResponse()->headers->get('Content-Type'));

            $crawler = $this->client->request('GET', '/admin/item/'.$item->getId()->toRfc4122().'/edit');
            $edit = $crawler->filter('form[name="GameItem"]')->form();
            $edit->setValues(['GameItem[priceCoins]' => '2']);
            $this->client->submit($edit);
            self::assertResponseRedirects();

            $this->delete('/admin/item/'.$item->getId()->toRfc4122().'/edit', '/admin/item/'.$item->getId()->toRfc4122().'/delete');
            self::getContainer()->get('doctrine')->getManager()->clear();
            self::assertNull(self::getContainer()->get('doctrine')->getRepository(GameItem::class)->findOneBy(['key' => $key]));
        } finally {
            unlink($path);
        }
    }

    public function testInvalidPublishedCandidateIsRenderedBackIntoItsAdminForm(): void
    {
        $this->loginAdmin('form-error-admin@grrind.app');
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $item = $manager->getRepository(GameItem::class)->findOneBy([]);
        $ruleset = $manager->find(GameRuleset::class, 1);
        self::assertInstanceOf(GameItem::class, $item);
        self::assertInstanceOf(GameRuleset::class, $ruleset);
        $price = $item->getPriceCoins();
        $revision = $ruleset->revision();

        $crawler = $this->client->request('GET', '/admin/item/'.$item->getId()->toRfc4122().'/edit');
        $form = $crawler->filter('form[name="GameItem"]')->form();
        $form->setValues(['GameItem[priceCoins]' => '-1']);
        $crawler = $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('le prix en pièces ne peut pas être négatif', $crawler->text());
        self::assertSame('-1', $crawler->filter('input[name="GameItem[priceCoins]"]')->attr('value'));
        $manager->clear();
        $stored = $manager->find(GameItem::class, $item->getId());
        $published = $manager->find(GameRuleset::class, 1);
        self::assertInstanceOf(GameItem::class, $stored);
        self::assertInstanceOf(GameRuleset::class, $published);
        self::assertSame($price, $stored->getPriceCoins());
        self::assertSame($revision, $published->revision());
    }

    public function testInvalidImageFormLeavesNoStagingFileBeforePersistence(): void
    {
        $this->loginAdmin('staging-invalid-form-admin@grrind.app');
        $directory = self::getContainer()->getParameter('kernel.project_dir').'/var/game-images';
        $before = $this->imageFiles($directory);
        $path = tempnam(sys_get_temp_dir(), 'grrind-invalid-image-');
        self::assertIsString($path);
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL8ywAAAABJRU5ErkJggg==', true));

        try {
            $crawler = $this->client->request('GET', '/admin/item/new');
            $form = $crawler->filter('form[name="GameItem"]')->form();
            $active = $form['GameItem[active]'];
            self::assertInstanceOf(ChoiceFormField::class, $active);
            $active->untick();
            $upload = $form['GameItem[imagePath][file]'];
            self::assertInstanceOf(FileFormField::class, $upload);
            $upload->upload($path);
            $form->setValues([
                'GameItem[key]' => 'INVALID_STAGING_'.bin2hex(random_bytes(4)),
                // L'image est valide ; c'est ce champ frère qui fait échouer le formulaire
                // avant persistEntity et vérifie le nettoyage défensif de .staging.
                'GameItem[sortOrder]' => 'pas-un-entier',
                'GameItem[rarity]' => 'COMMON',
                'GameItem[kind]' => 'EQUIPMENT',
                'GameItem[slot]' => 'FEET',
                'GameItem[priceCoins]' => '1',
                'GameItem[shopMinimumLevel]' => '',
                'GameItem[translations][fr][name]' => 'Invalide',
                'GameItem[translations][en][name]' => 'Invalid',
            ]);
            $this->client->submit($form);

            self::assertResponseStatusCodeSame(422);
            self::assertSame($before, $this->imageFiles($directory));
        } finally {
            unlink($path);
        }
    }

    public function testEditingAnItemShowsItsCurrentPublicImagePreview(): void
    {
        $this->loginAdmin('image-preview-admin@grrind.app');
        $item = self::getContainer()->get('doctrine')->getRepository(GameItem::class)->findOneBy([]);
        self::assertInstanceOf(GameItem::class, $item);

        $crawler = $this->client->request('GET', '/admin/item/'.$item->getId()->toRfc4122().'/edit');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('img[src="/game-images/'.$item->getImagePath().'"]')->count());
    }

    public function testConfigurationDetailsRenderTheirStructuredValues(): void
    {
        $this->loginAdmin('configuration-details-admin@grrind.app');
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $item = $manager->getRepository(GameItem::class)->findOneBy([]);
        $title = $manager->getRepository(GameTitle::class)->findOneBy([]);
        $enemy = $manager->getRepository(GameEnemy::class)->findOneBy([]);
        $table = $manager->getRepository(GameLootTable::class)->findOneBy([]);
        self::assertInstanceOf(GameItem::class, $item);
        self::assertInstanceOf(GameTitle::class, $title);
        self::assertInstanceOf(GameEnemy::class, $enemy);
        self::assertInstanceOf(GameLootTable::class, $table);

        foreach ([
            '/admin/item/'.$item->getId()->toRfc4122(),
            '/admin/title/'.$title->getId()->toRfc4122(),
            '/admin/enemy/'.$enemy->getId()->toRfc4122(),
            '/admin/loot-table/'.$table->getId()->toRfc4122(),
            '/admin/settings/1',
        ] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
        }
    }

    private function delete(string $editUrl, string $deleteUrl): void
    {
        $page = $this->client->request('GET', $editUrl);
        $token = $page->filter('#action-confirmation-form input[name="token"]')->attr('value');
        self::assertIsString($token);
        $this->client->request('POST', $deleteUrl, ['token' => $token]);
        self::assertResponseRedirects();
    }

    private function loginAdmin(string $email = 'crud-http-admin@grrind.app'): void
    {
        $this->openAccount($email);
        $users = self::getContainer()->get(UserRepository::class);
        $user = $users->ofEmail($email);
        self::assertNotNull($user);
        $user->grant(Role::Admin);
        $users->commit();

        $login = $this->client->request('GET', '/admin/login');
        $token = $login->filter('input[name="_csrf_token"]')->attr('value');
        self::assertIsString($token);
        $this->client->request('POST', '/admin/login', ['_username' => $email, '_password' => 'un-mot-de-passe-assez-long', '_csrf_token' => $token]);
    }

    private function inactiveEnemy(string $key, int $sortOrder, int $minimumLevel = 99): GameEnemy
    {
        $enemy = new GameEnemy();
        $enemy->setKey($key);
        $enemy->setActive(false);
        $enemy->setSortOrder($sortOrder);
        $enemy->setMinimumLevel($minimumLevel);
        $enemy->setHp(100);
        $enemy->setDamage(1);
        $enemy->setTranslations(['fr' => ['name' => 'Ennemi de paire'], 'en' => ['name' => 'Pair enemy']]);

        return $enemy;
    }

    private function inactiveChest(string $key, int $sortOrder): GameItem
    {
        $item = new GameItem();
        $item->setKey($key);
        $item->setActive(false);
        $item->setSortOrder($sortOrder);
        $item->setKind('CHEST');
        $item->setTranslations(['fr' => ['name' => 'Coffre de paire'], 'en' => ['name' => 'Pair chest']]);

        return $item;
    }

    private function inactiveLoot(string $kind, string $key, int $sortOrder): GameLootTable
    {
        $table = new GameLootTable();
        $table->setKind($kind);
        $table->setKey($key);
        $table->setActive(false);
        $table->setSortOrder($sortOrder);
        $table->setCoinsMinimum(0);
        $table->setCoinsMaximum(1);
        $table->setEntries([['item' => null, 'weight' => 1], ['item' => 'WORN_RUNNING_SHOES', 'weight' => 1]]);

        return $table;
    }

    /** @return list<string> */
    private function imageFiles(string $directory): array
    {
        $files = [...(glob($directory.'/*') ?: []), ...(glob($directory.'/.staging/*') ?: [])];
        $names = array_map(static fn (string $path): string => str_replace($directory.'/', '', $path), $files);
        sort($names);

        return $names;
    }
}
