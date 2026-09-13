<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Domain\GameDesignCampaign;
use App\Admin\Domain\GameDesignCombat;
use App\Admin\Domain\GameDesignProfileGenerator;
use App\Admin\Domain\GameDesignRun;
use App\Admin\Domain\GameDesignRunKind;
use App\Identity\Domain\Role;
use App\Identity\Infrastructure\Doctrine\UserRepository;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class GameDesignCampaignBudgetTest extends ApiTestCase
{
    public function testFullMatrixReachesTheCombinedBudgetAndRendersAccessibleResults(): void
    {
        $this->openAccount('campaign-budget@grrind.app');
        $users = self::getContainer()->get(UserRepository::class);
        $user = $users->ofEmail('campaign-budget@grrind.app');
        self::assertNotNull($user);
        $user->grant(Role::Admin);
        $users->commit();
        $this->client->loginUser($user, 'admin');
        $snapshot = GameDesignFixture::snapshot();
        /** @var array{combat:array{fighter:array<string,int>,enemies:list<array<string,mixed>>}} $snapshot */
        $snapshot['combat']['fighter']['max_attacks'] = 500;
        $snapshot['combat']['fighter']['base_hp'] = 1000000;
        $snapshot['combat']['enemies'] = [];
        $enemies = [];
        for ($i = 0; $i < 10; ++$i) {
            $enemies[] = 'enemy-'.$i;
            $snapshot['combat']['enemies'][] = ['key' => 'enemy-'.$i, 'level' => $i + 1, 'hp' => 100000000, 'damage' => 1, 'mitigation_permille' => 0, 'combo_permille' => 0, 'dodge_permille' => 0];
        }
        $profiles = new GameDesignProfileGenerator()->generate(50, 10000, 42);
        $snapshots = ['published' => $snapshot, 'draft' => $snapshot];
        $started = hrtime(true);
        $result = new GameDesignCampaign(new GameDesignCombat())->run($snapshots, $profiles, $enemies, 1, 42, 60);
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $run = new GameDesignRun(GameDesignRunKind::Campaign, ['profile' => ['name' => 'Budget maximal'], 'profiles' => $profiles, 'enemies' => $enemies, 'seed' => 42], $snapshots, $result);
        $manager->persist($run);
        $manager->flush();
        $computeMs = (hrtime(true) - $started) / 1000000;
        self::assertSame(500000, $result['attemptBudget']);
        /** @var array<string,array{limitRate:float,n:int}> $global */
        $global = $result['global'];
        self::assertSame(100.0, $global['published']['limitRate']);
        self::assertSame(100.0, $global['draft']['limitRate']);
        self::assertSame(500, $global['draft']['n']);
        $started = hrtime(true);
        $this->client->request('GET', '/admin/game-design/campaigns/'.$run->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(500, 'table.heatmap form');
        self::assertSelectorTextContains('body', 'Faible effectif');
        $renderMs = (hrtime(true) - $started) / 1000000;
        fwrite(\STDERR, \sprintf("\n#277 campagne plafond 50×10×1×2, 500000 tentatives : calcul+stockage %.1f ms ; GET %.1f ms ; pic %.1f MiB\n", $computeMs, $renderMs, memory_get_peak_usage(true) / 1048576));
    }
}
