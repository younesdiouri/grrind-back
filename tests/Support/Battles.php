<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Combat\Domain\Battle;
use App\Combat\Domain\BattleFinished;
use App\Combat\Domain\BattleOutcome;
use App\Combat\Domain\BattleResult;
use App\Combat\Domain\BattleStarted;
use App\Combat\Domain\Enemy;
use App\Combat\Domain\Fighter;
use App\Combat\Infrastructure\Doctrine\BattleRepository;
use App\Shared\Domain\Activity\AttributeGains;
use DateTimeImmutable;

/**
 * De quoi écrire un combat directement en base, un `foughtAt` choisi par l'appelant compris —
 * même geste que {@see Workouts} pour les workouts, pour la même raison : un tirage aléatoire
 * n'a pas de position à dicter, alors qu'un test de tri ou de pagination a besoin d'un instant
 * précis, parfois volontairement identique entre deux combats.
 *
 * `Battle::conclude()` est le seul point de construction (voir son docblock) ; cette classe ne
 * fait que lui fournir des snapshots valides quelconques, sans prétendre à un vrai simulateur —
 * ce que ces tests vérifient n'est ni le combat ni son équilibrage, déjà prouvés ailleurs
 * (#208-#211), mais la lecture d'un historique.
 *
 * @phpstan-require-extends ApiTestCase
 */
trait Battles
{
    protected function recordBattle(
        Account $account,
        DateTimeImmutable $foughtAt,
        BattleResult $result = BattleResult::Victory,
        int $turns = 3,
        string $enemyKey = 'SAND_JACKAL',
    ): string {
        $repository = self::getContainer()->get(BattleRepository::class);
        self::assertInstanceOf(BattleRepository::class, $repository);

        $battle = Battle::conclude(
            $account->id,
            new AttributeGains(0, 0, 0, 0),
            0,
            new Fighter(140, 16, 0, 0, 0),
            new Enemy($enemyKey, 1, 120, 12, 50, 40, 30),
            new Fighter(120, 12, 50, 40, 30),
            new BattleOutcome(
                $result,
                [new BattleStarted(140, 120), new BattleFinished($result)],
                $turns,
            ),
            random_bytes(32),
            'v1-000000000000',
            $foughtAt,
        );

        $repository->add($battle);
        $repository->commit();

        return $battle->id()->toRfc4122();
    }
}
