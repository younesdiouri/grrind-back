<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Domain\GameDesignProfile;
use App\Admin\Domain\GameDesignProgram;
use App\Admin\Domain\GameDesignRun;
use App\Identity\Domain\Role;
use App\Identity\Infrastructure\Doctrine\UserRepository;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class GameDesignLimitsHttpTest extends ApiTestCase
{
    public function testMaximumProgressionIsIsolatedAndItsHttpCostIsMeasured(): void
    {
        $manager = $this->admin();
        $profile = new GameDesignProfile();
        $profile->setName('Budget');
        $program = new GameDesignProgram();
        $program->setName('52 semaines');
        $program->setWeeks(52);
        $sessions = [];
        for ($day = 1; $day <= 7; ++$day) {
            foreach ([8, 18] as $hour) {
                $sessions[] = ['day' => $day, 'hour' => $hour, 'minute' => 0, 'discipline' => 'RUNNING', 'duration' => 3600, 'distance' => 10000, 'elevation' => 100];
            }
        }
        $program->setSessions($sessions);
        $manager->persist($profile);
        $manager->persist($program);
        $manager->flush();
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
        $page = $this->client->request('GET', '/admin/game-design/programs/'.$program->getId()->toRfc4122().'/progression');
        self::assertResponseIsSuccessful();
        $started = hrtime(true);
        $this->client->submit($page->selectButton('Comparer la progression')->form());
        self::assertResponseRedirects();
        $postMs = (hrtime(true) - $started) / 1000000;
        $started = hrtime(true);
        $page = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $getMs = (hrtime(true) - $started) / 1000000;
        self::assertSelectorTextContains('body', '52 semaines');
        self::assertSelectorCount(104, 'a[href*="/weeks/"]');
        self::assertSame($before, $counts());
        $run = $manager->getRepository(GameDesignRun::class)->findOneBy([]);
        self::assertNotNull($run);
        $this->client->request('GET', '/admin/game-design/runs/'.$run->getId()->toRfc4122().'/archive');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        $archive = self::decode($this->client->getResponse());
        self::assertSame($run->snapshots(), $archive['snapshots']);
        self::assertSame($run->input(), $archive['input']);
        fwrite(\STDERR, \sprintf("\n#277 HTTP 52 × 14 : POST %.1f ms ; GET résultat %.1f ms ; pic %.1f MiB\n", $postMs, $getMs, memory_get_peak_usage(true) / 1048576));
    }

    public function testMaximumCombatHttpBudgetKeepsTheFullTimelineWithPagination(): void
    {
        $manager = $this->admin();
        $snapshot = GameDesignFixture::snapshot();
        /** @var array{combat: array{fighter: array<string,int>}} $snapshot */
        $snapshot['combat']['fighter']['max_attacks'] = 10000;
        $snapshot['combat']['fighter']['base_hp'] = 1000000;
        $profile = GameDesignFixture::profile();
        $origin = new GameDesignRun(\App\Admin\Domain\GameDesignRunKind::Progression, ['profile' => $profile], ['published' => $snapshot, 'draft' => $snapshot], ['published' => ['weeks' => [['state' => ['profile' => $profile]]]]]);
        $manager->persist($origin);
        $manager->flush();
        $page = $this->client->request('GET', '/admin/game-design/runs/'.$origin->getId()->toRfc4122().'/weeks/1/published/combat');
        self::assertResponseIsSuccessful();
        $form = $page->selectButton('Comparer les combats')->form();
        $form->setValues(['form[samples]' => '25', 'form[custom][hp]' => '100000000', 'form[custom][damage]' => '1']);
        $started = hrtime(true);
        $this->client->submit($form);
        self::assertResponseRedirects();
        $postMs = (hrtime(true) - $started) / 1000000;
        $started = hrtime(true);
        $page = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $getMs = (hrtime(true) - $started) / 1000000;
        self::assertSelectorTextContains('body', 'événements au total');
        self::assertSelectorCount(200, 'details ol li');
        $this->client->click($page->selectLink('Page suivante')->first()->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Page 2');
        self::assertSelectorCount(200, 'details ol li');
        fwrite(\STDERR, \sprintf("\n#277 HTTP 500000 tentatives : POST %.1f ms ; GET paginé %.1f ms ; pic %.1f MiB\n", $postMs, $getMs, memory_get_peak_usage(true) / 1048576));
    }

    public function testForgedProgramBoundsAndCsrfAreRejected(): void
    {
        $manager = $this->admin();
        $page = $this->client->request('GET', '/admin/game-design/programs/new');
        $values = $page->selectButton('Enregistrer le programme')->form()->getPhpValues();
        self::assertIsArray($values['game_design_program']);
        $values['game_design_program']['name'] = 'Invalide';
        $values['game_design_program']['weeks'] = '53';
        $values['game_design_program']['sessions'] = array_fill(0, 15, ['day' => '1', 'hour' => '25', 'minute' => '0', 'discipline' => 'RUNNING', 'duration' => '-1', 'distance' => '', 'elevation' => '']);
        $this->client->request('POST', '/admin/game-design/programs/new', $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $manager->getRepository(GameDesignProgram::class)->count([]));
        $values['game_design_program']['weeks'] = '1';
        $values['game_design_program']['sessions'] = [];
        $values['game_design_program']['_token'] = 'forged';
        $this->client->request('POST', '/admin/game-design/programs/new', $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $manager->getRepository(GameDesignProgram::class)->count([]));
    }

    private function admin(): EntityManagerInterface
    {
        $this->openAccount('limits@grrind.app');
        $users = self::getContainer()->get(UserRepository::class);
        $user = $users->ofEmail('limits@grrind.app');
        self::assertNotNull($user);
        $user->grant(Role::Admin);
        $users->commit();
        $this->client->loginUser($user, 'admin');

        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
