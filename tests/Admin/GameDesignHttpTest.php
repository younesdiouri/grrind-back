<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Domain\GameItem;
use App\Admin\Domain\GameRuleset;
use App\Admin\Infrastructure\GameRulesetPublisher;
use App\Identity\Domain\Role;
use App\Identity\Infrastructure\Doctrine\UserRepository;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class GameDesignHttpTest extends ApiTestCase
{
    public function testDraftEditsAreIsolatedAndStaleFormsCannotSaveOrPublish(): void
    {
        $this->openAccount('designer@grrind.app');
        $users = self::getContainer()->get(UserRepository::class);
        $user = $users->ofEmail('designer@grrind.app');
        self::assertNotNull($user);
        $user->grant(Role::Admin);
        $users->commit();
        $this->client->loginUser($user, 'admin');
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $item = $manager->getRepository(GameItem::class)->findOneBy([]);
        self::assertInstanceOf(GameItem::class, $item);
        $price = $item->getPriceCoins();
        $id = $item->getId();
        $ruleset = $manager->find(GameRuleset::class, 1);
        self::assertInstanceOf(GameRuleset::class, $ruleset);
        $snapshot = $ruleset->snapshot();
        $revision = $ruleset->revision();
        $editUrl = '/admin/item/'.$id->toRfc4122().'/edit';
        try {
            $page = $this->client->request('GET', '/admin/game-design');
            self::assertResponseIsSuccessful();
            $oldPublication = $page->filter('form[name="form"]')->form();
            $this->client->request('POST', '/admin/game-design', ['form' => ['revision' => '1', 'publish' => '', '_token' => 'forged']]);
            self::assertResponseStatusCodeSame(422);
            $stale = $this->client->request('GET', $editUrl)->filter('form[name="GameItem"]')->form();
            $current = $this->client->request('GET', $editUrl)->filter('form[name="GameItem"]')->form();
            $current->setValues(['GameItem[priceCoins]' => (string) ($price + 1)]);
            $this->client->submit($current);
            self::assertResponseRedirects();
            $manager->clear();
            $unchanged = $manager->find(GameRuleset::class, 1);
            self::assertInstanceOf(GameRuleset::class, $unchanged);
            self::assertSame($snapshot, $unchanged->snapshot());
            self::assertSame($revision, $unchanged->revision());
            $stale->setValues(['GameItem[priceCoins]' => (string) ($price + 2)]);
            $this->client->submit($stale);
            self::assertResponseStatusCodeSame(422);
            $this->client->submit($oldPublication);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'Actualisez');
            $page = $this->client->request('GET', '/admin/game-design');
            self::assertSelectorTextContains('table', 'price_coins');
            $this->client->submit($page->filter('form[name="form"]')->form());
            self::assertResponseRedirects('/admin/game-design');
            $manager = self::getContainer()->get(EntityManagerInterface::class);
            $manager->clear();
            $published = $manager->find(GameRuleset::class, 1);
            self::assertInstanceOf(GameRuleset::class, $published);
            self::assertSame($revision + 1, $published->revision());
            self::assertNotSame($snapshot, $published->snapshot());
            $this->client->followRedirect();
            self::assertSelectorTextContains('body', $user->getUserIdentifier());
        } finally {
            $manager = self::getContainer()->get('doctrine')->resetManager();
            self::assertInstanceOf(EntityManagerInterface::class, $manager);
            $original = $manager->find(GameItem::class, $id);
            self::assertInstanceOf(GameItem::class, $original);
            $original->setPriceCoins($price);
            $publisher = self::getContainer()->get(GameRulesetPublisher::class);
            $manager->wrapInTransaction(static function () use ($manager, $publisher): void {
                $manager->flush();
                $publisher->publish($manager);
            });
            $publisher->invalidateAfterCommit();
        }
    }

    public function testTheWorkshopRequiresAnAdminSession(): void
    {
        $this->client->request('GET', '/admin/game-design');
        self::assertResponseRedirects('/admin/login');
    }
}
