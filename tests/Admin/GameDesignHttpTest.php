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

    public function testFictionalCombatHasNoGameplaySideEffectsAndKeepsItsInputs(): void
    {
        $this->openAccount('lab@grrind.app');
        $users = self::getContainer()->get(UserRepository::class);
        $user = $users->ofEmail('lab@grrind.app');
        self::assertNotNull($user);
        $user->grant(Role::Admin);
        $users->commit();
        $this->client->loginUser($user, 'admin');
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $manager->getConnection();
        $tables = array_filter($connection->createSchemaManager()->listTableNames(), static fn (string $table): bool => !str_starts_with($table, 'game_design_'));
        $counts = static function () use ($connection, $tables): array {
            $result = [];
            foreach ($tables as $table) {
                $result[$table] = $connection->fetchOne('SELECT COUNT(*) FROM '.$connection->quoteSingleIdentifier($table));
            }

            return $result;
        };
        $before = $counts();
        $page = $this->client->request('GET', '/admin/game-design/profiles/new');
        self::assertResponseIsSuccessful();
        $form = $page->selectButton('Enregistrer le profil')->form();
        $form->setValues(['game_design_profile[name]' => 'Sprinteuse test', 'game_design_profile[strength]' => '100', 'game_design_profile[endurance]' => '200', 'game_design_profile[mobility]' => '300', 'game_design_profile[dexterity]' => '400']);
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/game-design/profiles');
        $page = $this->client->followRedirect();
        $page = $this->client->click($page->selectLink('Combats')->link());
        self::assertResponseIsSuccessful();
        $form = $page->selectButton('Comparer les combats')->form();
        $form->setValues(['form[samples]' => '3']);
        $this->client->submit($form);
        self::assertResponseRedirects();
        $page = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Sprinteuse test');
        self::assertSelectorTextContains('body', '3 combats par version');
        self::assertSame($before, $counts());
        $run = $manager->getRepository(\App\Admin\Domain\GameDesignRun::class)->findOneBy([]);
        self::assertNotNull($run);
        $input = $run->input();
        $snapshots = $run->snapshots();
        /** @var array{profile: array{name: string, strength: int, endurance: int, mobility: int, dexterity: int, vitalityOverride: ?int, equipment: list<string>}, enemyKey: string, customEnemy: array{hp: int, damage: int, mitigationPermille: int, comboPermille: int, dodgePermille: int, maintenancePermille: int, criticalChancePermille: int, guardPermille: int, criticalResistancePermille: int, cooldownReductionPermille: int, precisionPermille: int}, samples: int, seed: int} $input */
        self::assertSame($run->result(), new \App\Admin\Domain\GameDesignCombat()->compare($snapshots['published'], $snapshots['draft'], $input['profile'], $input['enemyKey'], $input['customEnemy'], $input['samples'], $input['seed']));
    }

    public function testTheWorkshopRequiresAnAdminSession(): void
    {
        $this->client->request('GET', '/admin/game-design');
        self::assertResponseRedirects('/admin/login');
    }
}
