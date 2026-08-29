<?php

declare(strict_types=1);

namespace App\Tests\Combat\Application;

use App\Combat\Application\FightBattle;
use App\Combat\Application\FightBattleHandler;
use App\Combat\Application\FighterFactory;
use App\Combat\Domain\Battle;
use App\Combat\Domain\BattleResult;
use App\Combat\Domain\BattleSimulator;
use App\Combat\Domain\EnemyCatalog;
use App\Combat\Infrastructure\Doctrine\BattleRepository;
use App\Shared\Application\PlayerProgression;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Le pipeline entier contre les vrais services du conteneur — `combat.yaml` chargé, la
 * vraie base. Ce qui compte ici ne se démontre pas en mémoire : que la ligne écrite
 * reproduit fidèlement ce que le moteur a produit, et que le `ruleset_version` qu'elle
 * porte est bien celui que le conteneur a chargé, pas une valeur écrite en dur dans le
 * handler.
 *
 * Pas de compte : le joueur n'est qu'un UUID nu, sans ligne de progression — c'est le cas
 * normal d'un compte tout juste inscrit, voir {@see PlayerProgression::untouched()}.
 */
final class FightBattleHandlerTest extends KernelTestCase
{
    private FightBattleHandler $handler;
    private BattleRepository $battles;
    private EntityManagerInterface $entityManager;
    private string $rulesetVersion;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $handler = $container->get(FightBattleHandler::class);
        self::assertInstanceOf(FightBattleHandler::class, $handler);
        $this->handler = $handler;

        $battles = $container->get(BattleRepository::class);
        self::assertInstanceOf(BattleRepository::class, $battles);
        $this->battles = $battles;

        $entityManager = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $this->rulesetVersion = (string) $container->getParameter('game.ruleset_version');

        $connection = $this->entityManager->getConnection();
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement('TRUNCATE combat_battle');
    }

    public function testAFreshPlayerFightsAndTheBattleIsWritten(): void
    {
        $playerId = Uuid::v7();

        $battle = ($this->handler)(new FightBattle($playerId));

        self::assertSame($playerId->toRfc4122(), $battle->playerId()->toRfc4122());
        self::assertContains($battle->result(), [BattleResult::Victory, BattleResult::Defeat]);
        self::assertGreaterThan(0, $battle->turns());

        $this->entityManager->clear();
        $reloaded = $this->battles->find($battle->id());
        self::assertInstanceOf(Battle::class, $reloaded);
        self::assertSame($battle->result(), $reloaded->result());

        // `assertEquals`, pas `assertSame` : PostgreSQL réordonne les clés d'un objet
        // JSONB (par longueur puis ordre alphabétique) au stockage — un objet JSON n'a pas
        // d'ordre de clé signifiant, seul l'ordre des éléments de la liste l'est, et celui-là
        // survit. Voir le docblock de `Battle`.
        self::assertEquals($battle->timeline(), $reloaded->timeline());
    }

    /**
     * Le `ruleset_version` écrit est celui du conteneur, pas une constante recopiée dans
     * le handler : le jour où `combat.yaml` change, cette ligne doit suivre sans qu'on
     * touche à `FightBattleHandler`.
     */
    public function testTheWrittenRulesetVersionIsTheContainerS(): void
    {
        $battle = ($this->handler)(new FightBattle(Uuid::v7()));

        self::assertSame($this->rulesetVersion, $battle->rulesetVersion());
    }

    /**
     * Deux combats joués à graine forcée identique, avec les mêmes collaborateurs que le
     * handler emploie (résolus depuis le conteneur, pas reconstruits à la main), écrivent
     * deux lignes identiques une fois relues en base — timeline, résultat et tours compris.
     * C'est ce qui rend une ligne auditable : la graine et les snapshots prouvent que la
     * timeline sort bien de ces entrées.
     */
    public function testTwoBattlesPlayedWithTheSameForcedSeedWriteIdenticalLines(): void
    {
        $container = self::getContainer();

        $fighters = $container->get(FighterFactory::class);
        self::assertInstanceOf(FighterFactory::class, $fighters);

        $enemies = $container->get(EnemyCatalog::class);
        self::assertInstanceOf(EnemyCatalog::class, $enemies);

        $simulator = $container->get(BattleSimulator::class);
        self::assertInstanceOf(BattleSimulator::class, $simulator);

        $clock = $container->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);
        $now = $clock->now();

        // 32 octets exacts, forcés — jamais un hash d'une chaîne, voir le docblock de
        // `Battle` pour le piège que ça a coûté au #209.
        $seed = random_bytes(32);

        $progression = PlayerProgression::untouched();
        $enemy = $enemies->forLevel($progression->level);
        $enemyFighter = $fighters->forEnemy($enemy);
        $player = $fighters->forPlayer($progression);

        $write = function () use ($simulator, $seed, $progression, $player, $enemy, $enemyFighter, $now): Battle {
            $outcome = $simulator->fight($player, $enemyFighter, new Randomizer(new Xoshiro256StarStar($seed)));

            $battle = Battle::conclude(
                Uuid::v7(),
                $progression->attributes,
                $progression->vitality,
                $player,
                $enemy,
                $enemyFighter,
                $outcome,
                $seed,
                $this->rulesetVersion,
                $now,
            );

            $this->battles->add($battle);
            $this->battles->commit();

            return $battle;
        };

        $first = $write();
        $second = $write();

        $this->entityManager->clear();

        $firstReloaded = $this->battles->find($first->id());
        $secondReloaded = $this->battles->find($second->id());
        self::assertInstanceOf(Battle::class, $firstReloaded);
        self::assertInstanceOf(Battle::class, $secondReloaded);

        self::assertSame($firstReloaded->result(), $secondReloaded->result());
        self::assertSame($firstReloaded->turns(), $secondReloaded->turns());
        self::assertSame($firstReloaded->timeline(), $secondReloaded->timeline());
        self::assertSame($firstReloaded->playerSnapshot(), $secondReloaded->playerSnapshot());
        self::assertSame($firstReloaded->enemySnapshot(), $secondReloaded->enemySnapshot());
        self::assertSame($firstReloaded->seed(), $secondReloaded->seed());
    }
}
