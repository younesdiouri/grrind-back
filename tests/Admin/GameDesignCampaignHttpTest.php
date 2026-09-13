<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Domain\GameDesignRun;
use App\Admin\Domain\GameDesignRunKind;
use App\Admin\Domain\GameItem;
use App\Admin\Infrastructure\GameRulesetPublisher;
use App\Identity\Domain\Role;
use App\Identity\Infrastructure\Doctrine\UserRepository;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class GameDesignCampaignHttpTest extends ApiTestCase
{
    public function testGeneratePreviewSaveCampaignChartsArchiveAndDrilldownWithoutGameplayWrites(): void
    {
        $this->openAccount('campaign@grrind.app');
        $users = self::getContainer()->get(UserRepository::class);
        $user = $users->ofEmail('campaign@grrind.app');
        self::assertNotNull($user);
        $user->grant(Role::Admin);
        $users->commit();
        $this->client->loginUser($user, 'admin');
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $manager->getConnection();
        $tables = array_filter($connection->createSchemaManager()->listTableNames(), static fn (string $table): bool => !str_starts_with($table, 'game_design_'));
        $counts = static function () use ($connection, $tables): array {
            $counts = [];
            foreach ($tables as $table) {
                $counts[$table] = $connection->fetchOne('SELECT COUNT(*) FROM '.$connection->quoteSingleIdentifier($table));
            }

            return $counts;
        };
        $before = $counts();
        $page = $this->client->request('GET', '/admin/game-design');
        self::assertSelectorExists('a[href="/admin/game-design/campaigns"]');
        $page = $this->client->request('GET', '/admin/game-design/campaigns');
        self::assertResponseIsSuccessful();
        $this->client->submit($page->selectButton('Prévisualiser les profils')->form());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Aperçu');
        self::assertSame(0, $manager->getRepository(GameDesignRun::class)->count([]));
        $page = $this->client->getCrawler();
        $this->client->submit($page->selectButton('Enregistrer ce lot')->form());
        self::assertResponseRedirects();
        $page = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '20 profils enregistrés');
        $form = $page->selectButton('Lancer la campagne')->form();
        $form->setValues(['form[target]' => '65']);
        $started = hrtime(true);
        $this->client->submit($form);
        self::assertResponseRedirects();
        $postMs = (hrtime(true) - $started) / 1000000;
        $page = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('table.heatmap');
        self::assertSelectorTextContains('body', 'Carte de chaleur');
        self::assertSelectorTextContains('body', 'Classements comparatifs');
        self::assertSelectorTextContains('body', 'Distributions normalisées');
        self::assertSelectorTextContains('body', '65 %');
        self::assertSelectorTextContains('body', 'Faible effectif');
        self::assertSame($before, $counts());
        $manager->clear();
        $run = $manager->getRepository(GameDesignRun::class)->findOneBy(['kind' => GameDesignRunKind::Campaign]);
        self::assertNotNull($run);
        $result = $run->result();
        $this->client->submit($page->selectButton('Premier duel')->first()->form());
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Premier combat');
        self::assertSame($before, $counts());
        $this->client->request('GET', '/admin/game-design/runs/'.$run->getId()->toRfc4122().'/archive');
        self::assertResponseIsSuccessful();
        $archive = self::decode($this->client->getResponse());
        // Le contrat est JSON : 100 et 100.0 désignent le même nombre.
        self::assertJsonStringEqualsJsonString(json_encode($result, \JSON_THROW_ON_ERROR), json_encode($archive['result'], \JSON_THROW_ON_ERROR));
        self::assertSame($run->snapshots(), $archive['snapshots']);
        $manager->clear();
        $detail = $manager->getRepository(GameDesignRun::class)->findOneBy(['kind' => GameDesignRunKind::Combat]);
        self::assertNotNull($detail);
        $detailResult = $detail->result();
        $item = $manager->getRepository(GameItem::class)->findOneBy([]);
        self::assertInstanceOf(GameItem::class, $item);
        $price = $item->getPriceCoins();
        $itemId = $item->getId();
        try {
            $item->setPriceCoins($price + 1);
            $publisher = self::getContainer()->get(GameRulesetPublisher::class);
            $manager->wrapInTransaction(static function () use ($manager, $publisher): void {
                $manager->flush();
                $publisher->publish($manager);
            });
            $publisher->invalidateAfterCommit();
            $page = $this->client->request('GET', '/admin/game-design/campaigns/'.$run->getId()->toRfc4122());
            self::assertResponseIsSuccessful();
            $this->client->submit($page->selectButton('Premier duel')->first()->form());
            self::assertResponseRedirects();
            $manager = self::getContainer()->get(EntityManagerInterface::class);
            $manager->clear();
            $details = $manager->getRepository(GameDesignRun::class)->findBy(['kind' => GameDesignRunKind::Combat]);
            self::assertCount(2, $details);
            foreach ($details as $replayed) {
                self::assertSame($run->snapshots(), $replayed->snapshots());
                self::assertSame($detailResult, $replayed->result());
            }
        } finally {
            $manager = self::getContainer()->get('doctrine')->resetManager();
            self::assertInstanceOf(EntityManagerInterface::class, $manager);
            $item = $manager->find(GameItem::class, $itemId);
            self::assertInstanceOf(GameItem::class, $item);
            $item->setPriceCoins($price);
            $publisher = self::getContainer()->get(GameRulesetPublisher::class);
            $manager->wrapInTransaction(static function () use ($manager, $publisher): void {
                $manager->flush();
                $publisher->publish($manager);
            });
            $publisher->invalidateAfterCommit();
        }
        fwrite(\STDERR, \sprintf("\n#277 campagne défaut 20 profils : POST %.1f ms\n", $postMs));
    }

    public function testCampaignsRequireAdminAndGenerationCannotBypassCsrf(): void
    {
        $this->client->request('GET', '/admin/game-design/campaigns');
        self::assertResponseRedirects('/admin/login');
        $this->openAccount('campaign-denied@grrind.app');
        $users = self::getContainer()->get(UserRepository::class);
        $user = $users->ofEmail('campaign-denied@grrind.app');
        self::assertNotNull($user);
        $user->grant(Role::Admin);
        $users->commit();
        $this->client->loginUser($user, 'admin');
        $this->client->request('POST', '/admin/game-design/campaigns', ['form' => ['name' => 'Forged', 'count' => '20', 'total' => '10000', 'seed' => '42', 'save' => '', '_token' => 'forged']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, self::getContainer()->get(EntityManagerInterface::class)->getRepository(GameDesignRun::class)->count([]));
    }
}
